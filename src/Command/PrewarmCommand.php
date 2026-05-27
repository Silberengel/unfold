<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Repository\FeaturedAuthorRepository;
use App\Service\ArticleCommentThreadLoader;
use App\Service\CacheService;
use App\Service\FeaturedAuthorSync;
use App\Service\MagazineContentService;
use App\Service\Nip05VerificationService;
use App\Service\HighlightSyncService;
use App\Service\MagazineRefresher;
use App\Service\Nip09DeletionApplier;
use App\Service\NostrClient;
use App\Service\NostrKeyHelper;
use App\Service\ProfileIdentityLinksBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Prewarms magazine index cache, author metadata cache, optional comment thread cache, and
 * kind-9802 highlights into MySQL. Comments remain cache-only; highlights use `article_highlight`.
 */
#[AsCommand(
    name: 'app:prewarm',
    description: 'Refresh magazine indices, NIP-09 deletions, profile metadata, NIP-05, comment caches, and highlight DB',
)]
final class PrewarmCommand extends Command
{
    public function __construct(
        private readonly MagazineRefresher $magazineRefresher,
        private readonly Nip09DeletionApplier $nip09DeletionApplier,
        private readonly CacheService $cacheService,
        private readonly NostrClient $nostrClient,
        private readonly ArticleRepository $articleRepository,
        private readonly MagazineContentService $magazineContent,
        private readonly ArticleCommentThreadLoader $commentThreadLoader,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
        private readonly FeaturedAuthorSync $featuredAuthorSync,
        private readonly Nip05VerificationService $nip05Verification,
        private readonly ProfileIdentityLinksBuilder $profileIdentityLinks,
        private readonly FeaturedAuthorRepository $featuredAuthorRepository,
        private readonly HighlightSyncService $highlightSyncService,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-magazine', null, InputOption::VALUE_NONE, 'Skip magazine 30040 index fetch')
            ->addOption('no-deletions', null, InputOption::VALUE_NONE, 'Skip NIP-09 kind 5 deletion sync (articles + magazine 30040 rows, profiles, relay lists, etc.)')
            ->addOption('deletion-since', null, InputOption::VALUE_REQUIRED, 'strtotime() window start for kind 5 fetch', '-2 month')
            ->addOption('no-metadata', null, InputOption::VALUE_NONE, 'Skip batched kind-0 profile prewarm (MySQL event table)')
            ->addOption('no-comments', null, InputOption::VALUE_NONE, 'Skip comment thread cache')
            ->addOption('magazine-budget', null, InputOption::VALUE_REQUIRED, 'Seconds wall time for the category 30040 phase only (root fetch is not counted; capped at 600s). If many slugs, raise this or set MAGAZINE_PREWARM_PREFER_SLUGS', '90')
            ->addOption('metadata-limit', null, InputOption::VALUE_REQUIRED, 'Max distinct author pubkeys to warm (0 = all)', '0')
            ->addOption('metadata-batch', null, InputOption::VALUE_REQUIRED, 'Kind-0 metadata: pubkeys per Nostr REQ (batched)', '50')
            ->addOption('comments-max', null, InputOption::VALUE_REQUIRED, 'Newest N magazine category articles to warm comment cache for (0 = all, order: createdAt DESC; excludes generic /articles feed-only rows)', '10')
            ->addOption('comments-budget', null, InputOption::VALUE_REQUIRED, 'Wall-clock seconds for the whole comments phase (Nostr fetches are slow; a single long thread can exceed a short budget; use 1200+ if prewarming many articles)', '600')
            ->addOption('no-highlights', null, InputOption::VALUE_NONE, 'Skip kind-9802 highlight fetch → MySQL')
            ->addOption('highlights-max', null, InputOption::VALUE_REQUIRED, 'Newest N magazine category articles to sync highlights for (0 = all; each Nostr fetch is slow — default 25 keeps prewarm bounded)', '25')
            ->addOption('highlights-budget', null, InputOption::VALUE_REQUIRED, 'Wall-clock seconds for the highlight sync phase', '600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->disableCliExecutionTimeLimit();
        $socketTo = (int) $this->params->get('nostr_relay_request_timeout_sec');
        if ($socketTo > 0) {
            // Align PHP stream layer with Nostr WebSocket timeout (avoids 60s default stalling a relay step).
            ini_set('default_socket_timeout', (string) $socketTo);
        }

        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('no-magazine')) {
            $budget = max(1, (int) $input->getOption('magazine-budget'));
            $io->section('Magazine index (kinds 30040)');
            try {
                $hb = (object) ['silent' => false];
                $bar = null;
                $this->runWithPcntlHeartbeat($io, 5, function () use ($io, $budget, &$bar, $hb): void {
                    $this->magazineRefresher->refreshFromRelays($budget, [], function (string $phase, array $p) use ($io, &$bar, $hb): void {
                        if ($phase === 'before_root') {
                            $io->writeln('   <comment>Fetching magazine root index (30040) from relays…</comment>');
                        } elseif ($phase === 'aborted') {
                            $hb->silent = true;
                            $this->cancelPcntlAlarm();
                        } elseif ($phase === 'after_root') {
                            $hb->silent = true;
                            $this->cancelPcntlAlarm();
                            $io->writeln('   <comment>Magazine root has no child <info>a</info> tag categories; only the root index was stored.</comment>');
                            $bar = $this->createPrewarmProgressBar(
                                $io,
                                max(1, (int) ($p['total_steps'] ?? 1)),
                                'Magazine index',
                            );
                            $bar->start();
                            $bar->setProgress(1);
                        } elseif ($phase === 'category_fetched' && $bar !== null) {
                            $bar->advance(1);
                            $slug = (string) ($p['slug'] ?? '');
                            $tSlug = $slug;
                            if (strlen($tSlug) > 70) {
                                $tSlug = substr($tSlug, 0, 67).'…';
                            }
                            $bar->setMessage($tSlug !== '' ? 'Category: '.$tSlug : 'Category');
                            if ($tSlug !== '') {
                                $ci = (int) ($p['category_index'] ?? 0);
                                $ct = (int) ($p['category_total'] ?? 0);
                                if ($ci > 0 && $ct > 0) {
                                    $io->writeln(sprintf(
                                        '   <info>[category %d/%d]</info> <comment>Fetched category index</comment> — <info>%s</info>',
                                        $ci,
                                        $ct,
                                        $tSlug
                                    ));
                                } else {
                                    $st = (int) ($p['step'] ?? 0);
                                    $tot = (int) ($p['total_steps'] ?? 0);
                                    if ($tot > 0) {
                                        $io->writeln(sprintf(
                                            '   <info>[%d/%d]</info> <comment>Fetched category index</comment> — <info>%s</info>',
                                            $st,
                                            $tot,
                                            $tSlug
                                        ));
                                    } else {
                                        $io->writeln(sprintf('   <comment>Fetched category index</comment> — <info>%s</info>', $tSlug));
                                    }
                                }
                            }
                        }
                    });
                }, $hb);
                if ($bar !== null) {
                    $bar->finish();
                    $io->newLine(2);
                }
                $io->success('Magazine indices refreshed (within budget).');
            } catch (\Throwable $e) {
                $this->logger->error('app:prewarm magazine failed', ['e' => $e]);
                $io->warning('Magazine refresh failed: '.$e->getMessage());
            } finally {
                $this->cancelPcntlAlarm();
            }
        } else {
            $io->note('Skipping magazine (--no-magazine).');
        }

        // MagazineRefresher used to set max_execution_time (~2×budget); re-assert unlimited before
        // any later Nostr phase (long-form can exceed that old cap and was causing max-time fatals).
        $this->disableCliExecutionTimeLimit();

        $io->section('Long-form in DB (category `a` tags — refresh from Nostr)');
        try {
            $n = $this->magazineContent->ingestLongformForAllMagazineCategories();
            if ($n === 0) {
                $io->note('No category `a` coordinates in the magazine store (or empty category indices).');
            } else {
                $io->writeln(sprintf('Fetched latest long-form for <info>%d</info> coordinate(s) (new rows + NIP-33 updates).', $n));
            }
            $report = $this->magazineContent->buildCategoryArticleDbCoverageReport();
            $missingCoords = $this->magazineContent->missingInDbCoordinatesFromCoverageReport($report);
            $attempt = 0;
            while ($missingCoords !== [] && $attempt < 2) {
                $attempt++;
                $io->writeln(sprintf(
                    'Retrying unresolved category coordinates from relays (attempt <info>%d</info>, coordinates: <comment>%d</comment>)…',
                    $attempt,
                    \count($missingCoords)
                ));
                $this->nostrClient->ingestLongformForCategoryCoordinates($missingCoords);
                $report = $this->magazineContent->buildCategoryArticleDbCoverageReport();
                $missingCoords = $this->magazineContent->missingInDbCoordinatesFromCoverageReport($report);
            }
            $this->printCategoryCoverageSummary($io, $report);
        } catch (\Throwable $e) {
            $this->logger->error('app:prewarm longform ingest failed', ['e' => $e]);
            $io->warning('Long-form backfill failed: '.$e->getMessage());
        }

        $io->section('Featured authors / NIP-05 source list');
        try {
            $fa = $this->featuredAuthorSync->reconcileListedAuthorsFromMagazineCategories();
            $io->writeln(sprintf(
                'Derived from category `a` tags: listed now <info>%d</info> · added <info>%d</info> · relisted <info>%d</info> · unlisted <comment>%d</comment>',
                $fa['listed_total'],
                $fa['added'],
                $fa['relisted'],
                $fa['unlisted'],
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('app:prewarm featured author reconcile', ['e' => $e->getMessage()]);
            $io->warning('Featured author reconcile failed: '.$e->getMessage());
        }

        $this->disableCliExecutionTimeLimit();

        if (!$input->getOption('no-deletions')) {
            $io->section('NIP-09 deletions (kind 5 → 30023/30024 / 30040 / legacy 30004 rows)');
            $sinceStr = (string) $input->getOption('deletion-since');
            $since = strtotime($sinceStr);
            if ($since === false) {
                $since = strtotime('-2 month');
            }
            $until = time();
            $deletionPubkeys = [];
            foreach ($this->articleRepository->findDistinctAuthorPubkeys() as $pk) {
                if (64 === \strlen($pk)) {
                    $deletionPubkeys[] = $pk;
                }
            }
            $npubParam = (string) $this->params->get('npub');
            if (str_starts_with($npubParam, 'npub')) {
                try {
                    $sitePk = $this->nostrKeyHelper->convertToHex($npubParam);
                    if ($sitePk !== '' && 64 === \strlen($sitePk) && !\in_array($sitePk, $deletionPubkeys, true)) {
                        $deletionPubkeys[] = $sitePk;
                    }
                } catch (\Throwable) {
                }
            }
            if ($deletionPubkeys === []) {
                $io->note('No author pubkeys; skipping kind 5 deletion fetch.');
            } else {
                $chunks = array_chunk($deletionPubkeys, 40);
                $nChunks = \count($chunks);
                $nipBar = $this->createPrewarmProgressBar($io, max(1, $nChunks), 'NIP-09 kind 5 (by author batch)');
                $nipBar->start();
                try {
                    $kind5 = $this->nostrClient->fetchKind5DeletionEventsForAuthors(
                        $deletionPubkeys,
                        $since,
                        $until,
                        40,
                        function (int $index, int $totalChunks, int $pubkeyCount) use ($nipBar): void {
                            $nipBar->advance(1);
                            $nipBar->setMessage(sprintf('Batch %d/%d · %d pubkeys', $index, $totalChunks, $pubkeyCount));
                        }
                    );
                } finally {
                    $nipBar->finish();
                    $io->newLine(2);
                }
                try {
                    $st = $this->nip09DeletionApplier->apply($kind5);
                    $io->writeln(sprintf(
                        'Kind 5 events: <info>%d</info> (deduped). NIP-23 long-form in DB (30023/30024) removed: <info>%d</info>. Magazine index in cache (30040) removed: root <info>%d</info>, category <info>%d</info>. Legacy home curation (30004) rows removed: <info>%d</info>.',
                        \count($kind5),
                        $st['articles_removed'],
                        $st['magazine_roots'],
                        $st['magazine_categories'],
                        $st['magazine_curation_30004']
                    ));
                } catch (\Throwable $e) {
                    $this->logger->error('app:prewarm NIP-09 failed', ['exception' => $e]);
                    $io->warning('NIP-09 step failed: '.$e->getMessage());
                }
            }
        } else {
            $io->note('Skipping NIP-09 deletions (--no-deletions).');
        }

        $this->disableCliExecutionTimeLimit();

        if (!$input->getOption('no-metadata')) {
            $io->section('Author metadata (cache)');
            $pubkeys = $this->articleRepository->findDistinctAuthorPubkeys();
            $npubParam = (string) $this->params->get('npub');
            if (str_starts_with($npubParam, 'npub')) {
                try {
                    $sitePk = $this->nostrKeyHelper->convertToHex($npubParam);
                    if ($sitePk !== '' && !\in_array($sitePk, $pubkeys, true)) {
                        $pubkeys[] = $sitePk;
                    }
                } catch (\Throwable) {
                    // ignore bad npub
                }
            }
            $limit = (int) $input->getOption('metadata-limit');
            if ($limit > 0) {
                $pubkeys = \array_slice($pubkeys, 0, $limit);
            }
            $pubkeysSeen = [];
            foreach ($pubkeys as $pk) {
                if (64 !== \strlen($pk)) {
                    continue;
                }
                $h = strtolower($pk);
                if (ctype_xdigit($h) && !isset($pubkeysSeen[$h])) {
                    $pubkeysSeen[$h] = true;
                }
            }
            $pubkeys = array_keys($pubkeysSeen);
            foreach ($this->featuredAuthorRepository->findAllListedOrderByLocalPart() as $fa) {
                $hx = strtolower($fa->getPubkeyHex());
                if (64 === \strlen($hx) && ctype_xdigit($hx) && !isset($pubkeysSeen[$hx])) {
                    $pubkeys[] = $hx;
                    $pubkeysSeen[$hx] = true;
                }
            }
            $toWarm = $pubkeys;
            $total = \count($toWarm);
            $n = 0;
            if ($total === 0) {
                $io->note('No valid author pubkeys to warm.');
            } else {
                $batchSize = max(1, min(200, (int) $input->getOption('metadata-batch')));
                $io->writeln(sprintf(
                    'Fetching kind-0 metadata: <info>%d</info> author(s) in Nostr requests of up to <info>%d</info> pubkeys each.',
                    $total,
                    $batchSize
                ));
                $bar = $this->createPrewarmProgressBar($io, $total, 'Kind-0 metadata');
                $bar->start();
                try {
                    foreach (array_chunk($toWarm, $batchSize) as $chunk) {
                        $fetched = $this->nostrClient->fetchProfilePrewarmWireBundlesForAuthors($chunk, $batchSize);
                        $n += $this->cacheService->putPrewarmMetadataBatch($chunk, $fetched);
                        $bar->advance(\count($chunk));
                        $p0 = $chunk[0];
                        $bar->setMessage('Batch up to · '.substr($p0, 0, 8).'…');
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('app:prewarm metadata batch failed', ['exception' => $e]);
                    $io->error($e->getMessage());
                    $bar->finish();
                    $io->newLine(2);

                    return Command::FAILURE;
                }
                $bar->finish();
                $io->newLine(2);
            }
            $io->success(sprintf('Warmed metadata for %d of %d author(s).', $n, $total));

            if ($toWarm !== []) {
                $domain = trim((string) $this->params->get('nip05_domain'));
                if ($domain !== '') {
                    $this->waitForSiteWellKnownBeforeVerification($io, $domain);
                }
                $io->writeln('Verifying <comment>NIP-05</comment> (HTTPS <comment>/.well-known/nostr.json</comment>, per identifier)…');
                $npubsForVerify = [];
                foreach ($toWarm as $hex) {
                    if (64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                        continue;
                    }
                    try {
                        $npubsForVerify[] = $this->nostrKeyHelper->convertPublicKeyToBech32(strtolower($hex));
                    } catch (\Throwable) {
                    }
                }
                $this->cacheService->prefetchMetadataForNpubs($npubsForVerify);
                $nt = 0;
                $nv = 0;
                foreach ($toWarm as $hex) {
                    if (64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                        continue;
                    }
                    $hex = strtolower($hex);
                    $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($hex);
                    $bundle = $this->cacheService->getMetadataBundle($npub);
                    $rows = $this->profileIdentityLinks->buildNip05($bundle['content'], $bundle['kind0_tags']);
                    $fa = $this->featuredAuthorRepository->findOneByPubkeyHex($hex);
                    if ($fa !== null && $fa->isListed() && $domain !== '') {
                        $rows = $this->profileIdentityLinks->mergeSiteNip05IntoList(
                            $rows,
                            $fa->getLocalPart().'@'.$domain
                        );
                    }
                    foreach ($rows as $r) {
                        ++$nt;
                        $label = $r['label'];
                        if ($this->nip05Verification->verifyAndCache($hex, $label)) {
                            ++$nv;
                        }
                    }
                }
                $failed = $nt - $nv;
                $io->writeln(sprintf(
                    '   <info>%d</info> identifier(s) checked: <info>%d</info> verified, <comment>%d</comment> not verified.',
                    $nt,
                    $nv,
                    $failed
                ));
            }
        } else {
            $io->note('Skipping metadata (--no-metadata).');
        }

        if ($input->getOption('no-comments')) {
            $io->note('Skipping comments (--no-comments).');
        } else {
            $maxArticles = (int) $input->getOption('comments-max');

            $io->section('Comment / interaction cache');
            $commentBudgetSeconds = max(1, (int) $input->getOption('comments-budget'));
            $commentPhaseStart = microtime(true);
            $deadline = $commentPhaseStart + $commentBudgetSeconds;
            $magazineList = $this->magazineContent->getAllMagazineCategoryArticlesForSyndication();
            if ($maxArticles > 0) {
                $magazineList = \array_slice($magazineList, 0, $maxArticles);
            }
            $articles = $magazineList;
            $articleCount = \count($articles);
            $w = 0;
            if ($articleCount === 0) {
                $io->note('No articles in DB to scan for comment cache.');
            } else {
                $cBar = $this->createPrewarmProgressBar($io, $articleCount, 'Comment threads');
                $cBar->start();
                try {
                    /** @var Article $article */
                    foreach ($articles as $article) {
                        if (microtime(true) >= $deadline) {
                            $io->warning(sprintf(
                                'Comment phase stopped: comments-budget reached (%s).',
                                $this->formatCommentBudgetSecondsPair(microtime(true) - $commentPhaseStart, $commentBudgetSeconds),
                            ));
                            break;
                        }
                        $slug = trim((string) $article->getSlug());
                        $pubkey = (string) $article->getPubkey();
                        if ($slug === '' || strlen($pubkey) !== 64) {
                            $cBar->advance(1);
                            $cBar->setMessage('skip · invalid row');

                            continue;
                        }
                        $kind = $article->getKind()->value;
                        $coordinate = $kind.':'.$pubkey.':'.$slug;
                        $msg = $slug;
                        if (strlen($msg) > 56) {
                            $msg = substr($msg, 0, 53).'…';
                        }
                        $cBar->setMessage($msg);
                        $eventHex = (string) ($article->getEventId() ?? '');
                        try {
                            $this->commentThreadLoader->load($coordinate, $eventHex !== '' ? $eventHex : null);
                            ++$w;
                        } catch (\Throwable $e) {
                            $this->logger->warning('app:prewarm comment load', ['coord' => $coordinate, 'error' => $e->getMessage()]);
                        }
                        $cBar->advance(1);
                    }
                } finally {
                    $this->finishPrewarmProgressBarWithoutFillingToMax($cBar, $io);
                }
            }
            $io->success(sprintf(
                'Warmed comment cache for %d of %d article(s). Comment phase wall time %s.',
                $w,
                $articleCount,
                $this->formatCommentBudgetSecondsPair(microtime(true) - $commentPhaseStart, $commentBudgetSeconds),
            ));
        }

        if ($input->getOption('no-highlights')) {
            $io->note('Skipping highlight DB sync (--no-highlights).');

            return Command::SUCCESS;
        }

        $maxH = (int) $input->getOption('highlights-max');
        $io->section('Highlights (kind 9802 → MySQL)');
        $hBudget = max(1, (int) $input->getOption('highlights-budget'));
        $hStart = microtime(true);
        $hDeadline = $hStart + $hBudget;
        $hList = $this->magazineContent->getAllMagazineCategoryArticlesForSyndication();
        if ($maxH > 0) {
            $hList = \array_slice($hList, 0, $maxH);
        }
        $hCount = \count($hList);
        $hW = 0;
        $hScanned = 0;
        if ($hCount === 0) {
            $io->note('No articles in DB to scan for highlights.');
        } else {
            $hBar = $this->createPrewarmProgressBar($io, $hCount, 'Kind 9802 highlights');
            $hBar->start();
            try {
                /** @var Article $article */
                foreach ($hList as $article) {
                    if (microtime(true) >= $hDeadline) {
                        $io->warning(sprintf('Highlight phase stopped: highlights-budget reached (%d s).', $hBudget));
                        break;
                    }
                    ++$hScanned;
                    $slug = trim((string) $article->getSlug());
                    $pubkey = (string) $article->getPubkey();
                    if ($slug === '' || strlen($pubkey) !== 64) {
                        $hBar->advance(1);
                        $hBar->setMessage('skip · invalid row');
                        continue;
                    }
                    $tmsg = $slug;
                    if (strlen($tmsg) > 56) {
                        $tmsg = substr($tmsg, 0, 53).'…';
                    }
                    $hBar->setMessage($tmsg);
                    try {
                        $hW += $this->highlightSyncService->syncForArticle($article);
                    } catch (\Throwable $e) {
                        $this->logger->warning('app:prewarm highlight', ['slug' => $slug, 'error' => $e->getMessage()]);
                    }
                    $hBar->advance(1);
                }
            } finally {
                $this->finishPrewarmProgressBarWithoutFillingToMax($hBar, $io);
            }
        }
        $io->success(sprintf(
            'Highlight rows written/updated: <info>%d</info> (articles scanned: <info>%d</info> of %d, wall time <info>%.0f</info>s / %d s budget).',
            $hW,
            $hScanned,
            $hCount,
            microtime(true) - $hStart,
            $hBudget
        ));

        return Command::SUCCESS;
    }

    private function waitForSiteWellKnownBeforeVerification(SymfonyStyle $io, string $domain): void
    {
        $expected = [];
        foreach ($this->featuredAuthorRepository->findAllListedOrderByLocalPart() as $row) {
            $local = trim((string) $row->getLocalPart());
            $hex = strtolower(trim((string) $row->getPubkeyHex()));
            if ($local === '' || 64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                continue;
            }
            $expected[$local] = $hex;
        }
        if ($expected === []) {
            return;
        }

        $io->writeln(sprintf(
            'Ensuring site NIP-05 directory is current before verification (<comment>%s</comment>, names: <info>%d</info>)…',
            $domain,
            \count($expected)
        ));
        $url = 'https://'.$domain.'/.well-known/nostr.json';
        $attempt = 0;
        $maxAttempts = 4;
        while ($attempt < $maxAttempts) {
            $attempt++;
            $payload = $this->fetchWellKnownNamesMap($url);
            if ($payload !== null && $this->wellKnownHasExpectedNames($payload, $expected)) {
                $io->writeln(sprintf('   <info>OK</info> /.well-known/nostr.json is up-to-date (attempt %d/%d).', $attempt, $maxAttempts));

                return;
            }
            if ($attempt < $maxAttempts) {
                usleep(1_500_000);
            }
        }
        $io->warning('Site /.well-known/nostr.json did not reflect current featured authors before verification; NIP-05 checks may fail transiently.');
    }

    /**
     * @return array<string, string>|null
     */
    private function fetchWellKnownNamesMap(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Unfold-Prewarm/1.0\r\nAccept: application/json\r\n",
                'timeout' => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($decoded) || !isset($decoded['names']) || !\is_array($decoded['names'])) {
            return null;
        }
        $out = [];
        foreach ($decoded['names'] as $k => $v) {
            if (!\is_string($k) || !\is_string($v)) {
                continue;
            }
            $key = trim($k);
            $hex = strtolower(trim($v));
            if ($key === '' || 64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                continue;
            }
            $out[$key] = $hex;
        }

        return $out;
    }

    /**
     * @param array<string, string> $names
     * @param array<string, string> $expected
     */
    private function wellKnownHasExpectedNames(array $names, array $expected): bool
    {
        foreach ($expected as $local => $hex) {
            if (!isset($names[$local]) || !hash_equals($hex, $names[$local])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Absolute used/budget wall seconds for the comment phase, e.g. "127.4/600 s" (not a percentage).
     */
    private function formatCommentBudgetSecondsPair(float $usedSeconds, int $budgetSeconds): string
    {
        $r = round($usedSeconds, 1);
        $uStr = abs($r - (float) (int) $r) < 0.01 ? (string) (int) $r : sprintf('%.1f', $r);

        return sprintf('%s/%d s', $uStr, $budgetSeconds);
    }

    private function createPrewarmProgressBar(SymfonyStyle $io, int $max, string $message = ''): ProgressBar
    {
        $bar = $io->createProgressBar($max);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%'."\n".'  <comment>%message%</comment> <info>%elapsed:6s%</info> ');
        $bar->setMessage($message);

        // Long %message% lines (e.g. category slugs) wider than the terminal make Symfony’s ProgressBar
        // shrink/expand the bar on every redraw; truncate so each line fits and the bar stays stable
        // and can use the full width to the right.
        $tw = (new Terminal())->getWidth();
        if ($tw < 40) {
            $tw = 80;
        }
        $messageMaxWidth = max(12, $tw - 18);
        $bar->setPlaceholderFormatter('message', function (ProgressBar $b) use ($messageMaxWidth): string {
            $m = (string) ($b->getMessage() ?? '');
            if ($m === '') {
                return '';
            }
            if (Helper::width($m) > $messageMaxWidth) {
                return Helper::substr($m, 0, max(1, $messageMaxWidth - 1)).'…';
            }

            return $m;
        });
        $bar->setBarWidth(max(20, $tw - 32));

        return $bar;
    }

    /**
     * ProgressBar::finish() sets progress to max; when the comment phase stops on budget, we only
     * completed part of the steps, so cap max to the current step first (or clear if nothing ran).
     */
    private function finishPrewarmProgressBarWithoutFillingToMax(ProgressBar $bar, SymfonyStyle $io): void
    {
        $max = (int) $bar->getMaxSteps();
        $done = (int) $bar->getProgress();
        if ($max > 0 && $done < $max && $done === 0) {
            $bar->clear();
        } else {
            if ($max > 0 && $done < $max && $done > 0) {
                $bar->setMaxSteps($done);
            }
            $bar->finish();
        }
        $io->newLine(2);
    }

    /**
     * @param object{silent?: bool} $controller
     */
    private function runWithPcntlHeartbeat(SymfonyStyle $io, int $sec, callable $work, ?object $controller = null): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_alarm')) {
            $work();

            return;
        }
        $t0 = time();
        $handler = function () use ($io, $t0, $sec, $controller): void {
            if ($controller !== null && !empty($controller->silent)) {
                return;
            }
            $io->writeln(sprintf('   <comment>… %ds elapsed</comment>', time() - $t0));
            \pcntl_alarm((int) ceil($sec));
        };
        \pcntl_async_signals(true);
        \pcntl_signal(\SIGALRM, $handler);
        \pcntl_alarm((int) ceil($sec));
        try {
            $work();
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    private function cancelPcntlAlarm(): void
    {
        if (\function_exists('pcntl_alarm')) {
            @\pcntl_alarm(0);
        }
    }

    private function disableCliExecutionTimeLimit(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
    }

    /**
     * @param array{
     *   categories: list<array{
     *     slug: string,
     *     title: string,
     *     event_id: string,
     *     listed_total: int,
     *     resolved_total: int,
     *     missing_total: int,
     *     entries: list<array{
     *       coordinate: string,
     *       status: string,
     *       reason: string,
     *       article_title?: string
     *     }>
     *   }>,
     *   totals: array{categories: int, listed: int, resolved: int, missing: int}
     * } $report
     */
    private function printCategoryCoverageSummary(SymfonyStyle $io, array $report): void
    {
        $io->section('Category index -> DB coverage');
        $tot = $report['totals'];
        $io->writeln(sprintf(
            'Categories: <info>%d</info> · listed coordinates: <info>%d</info> · in DB: <info>%d</info> · missing: <comment>%d</comment>',
            (int) $tot['categories'],
            (int) $tot['listed'],
            (int) $tot['resolved'],
            (int) $tot['missing'],
        ));
        foreach ($report['categories'] as $cat) {
            $title = trim((string) $cat['title']);
            $slug = (string) $cat['slug'];
            $eventId = (string) $cat['event_id'];
            $io->writeln(sprintf(
                ' - <info>%s</info> (%s) · event <comment>%s</comment> · listed <info>%d</info>, in DB <info>%d</info>, missing <comment>%d</comment>',
                $title !== '' ? $title : $slug,
                $slug,
                $eventId !== '' ? $eventId : 'n/a',
                (int) $cat['listed_total'],
                (int) $cat['resolved_total'],
                (int) $cat['missing_total'],
            ));
            foreach ($cat['entries'] as $entry) {
                $coord = (string) $entry['coordinate'];
                if ($coord === '') {
                    continue;
                }
                $status = (string) $entry['status'];
                if ($status === 'resolved') {
                    $titleOut = trim((string) ($entry['article_title'] ?? ''));
                    $io->writeln(sprintf(
                        '    + <info>OK</info> %s%s',
                        $coord,
                        $titleOut !== '' ? ' -> '.$titleOut : ''
                    ));
                } elseif ($status === 'skipped') {
                    $io->writeln(sprintf('    · <info>sub-index</info> %s', $coord));
                } else {
                    $reason = (string) $entry['reason'];
                    $io->writeln(sprintf('    - <comment>MISSING</comment> %s (%s)', $coord, $reason));
                }
            }
        }
    }
}
