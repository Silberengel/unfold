<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\ArticleCommentThreadLoader;
use App\Service\CacheService;
use App\Service\MagazineRefresher;
use App\Service\NostrClient;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Prewarms magazine index cache, author metadata cache, and optional comment thread cache.
 * Does not persist comments to MySQL; comments are cache-only in this app.
 */
#[AsCommand(
    name: 'app:prewarm',
    description: 'Refresh magazine indices, profile metadata cache, and comment thread caches (use --no-comments to skip comments)',
)]
final class PrewarmCommand extends Command
{
    public function __construct(
        private readonly MagazineRefresher $magazineRefresher,
        private readonly CacheService $cacheService,
        private readonly NostrClient $nostrClient,
        private readonly ArticleRepository $articleRepository,
        private readonly ArticleCommentThreadLoader $commentThreadLoader,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-magazine', null, InputOption::VALUE_NONE, 'Skip magazine 30040 index fetch')
            ->addOption('no-metadata', null, InputOption::VALUE_NONE, 'Skip Nostr profile metadata cache')
            ->addOption('no-comments', null, InputOption::VALUE_NONE, 'Skip comment thread cache')
            ->addOption('magazine-budget', null, InputOption::VALUE_REQUIRED, 'Seconds wall time for magazine relay refresh', '30')
            ->addOption('metadata-limit', null, InputOption::VALUE_REQUIRED, 'Max distinct author pubkeys to warm (0 = all)', '0')
            ->addOption('metadata-batch', null, InputOption::VALUE_REQUIRED, 'Kind-0 metadata: pubkeys per Nostr REQ (batched)', '50')
            ->addOption('comments-max', null, InputOption::VALUE_REQUIRED, 'Newest N articles to warm comment cache for (0 = all, order: createdAt DESC)', '20')
            ->addOption('comments-budget', null, InputOption::VALUE_REQUIRED, 'Max seconds for the whole comments phase', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->disableCliExecutionTimeLimit();

        $io = new SymfonyStyle($input, $output);
        $keys = new Key();

        if (!$input->getOption('no-magazine')) {
            $budget = max(1, (int) $input->getOption('magazine-budget'));
            $io->section('Magazine index (kinds 30040)');
            try {
                $this->magazineRefresher->refreshFromRelays($budget, []);
                $io->success('Magazine indices refreshed (within budget).');
            } catch (\Throwable $e) {
                $this->logger->error('app:prewarm magazine failed', ['e' => $e]);
                $io->warning('Magazine refresh failed: '.$e->getMessage());
            }
        } else {
            $io->note('Skipping magazine (--no-magazine).');
        }

        // MagazineRefresher sets max_execution_time (e.g. 60 for budget 30); restore before metadata.
        $this->disableCliExecutionTimeLimit();

        if (!$input->getOption('no-metadata')) {
            $io->section('Author metadata (cache)');
            $pubkeys = $this->articleRepository->findDistinctAuthorPubkeys();
            $npubParam = (string) $this->params->get('npub');
            if (str_starts_with($npubParam, 'npub')) {
                try {
                    $sitePk = $keys->convertToHex($npubParam);
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
            $toWarm = [];
            foreach ($pubkeys as $pubkey) {
                if (strlen($pubkey) === 64) {
                    $toWarm[] = $pubkey;
                }
            }
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
                $bar = $io->createProgressBar($total);
                $bar->start();
                try {
                    foreach (array_chunk($toWarm, $batchSize) as $chunk) {
                        $fetched = $this->nostrClient->fetchKind0MetadataForAuthors($chunk, $batchSize);
                        $n += $this->cacheService->putPrewarmMetadataBatch($chunk, $fetched, $keys);
                        $bar->advance(\count($chunk));
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
        } else {
            $io->note('Skipping metadata (--no-metadata).');
        }

        if ($input->getOption('no-comments')) {
            $io->note('Skipping comments (--no-comments).');

            return Command::SUCCESS;
        }

        $maxArticles = (int) $input->getOption('comments-max');

        $io->section('Comment / interaction cache');
        $deadline = microtime(true) + max(1, (int) $input->getOption('comments-budget'));
        $qb = $this->articleRepository->createQueryBuilder('a')
            ->where('a.slug IS NOT NULL')
            ->andWhere("a.slug != ''")
            ->andWhere('a.pubkey IS NOT NULL')
            ->andWhere("a.pubkey != ''")
            ->orderBy('a.createdAt', 'DESC');
        if ($maxArticles > 0) {
            $qb->setMaxResults($maxArticles);
        }
        $articles = $qb->getQuery()->getResult();
        $w = 0;
        /** @var Article $article */
        foreach ($articles as $article) {
            if (microtime(true) >= $deadline) {
                $io->warning('Comment phase stopped: comments-budget reached.');
                break;
            }
            $slug = trim((string) $article->getSlug());
            $pubkey = (string) $article->getPubkey();
            if ($slug === '' || strlen($pubkey) !== 64) {
                continue;
            }
            $kind = $article->getKind()?->value ?? 30023;
            $coordinate = $kind.':'.$pubkey.':'.$slug;
            $eventHex = (string) ($article->getEventId() ?? '');
            try {
                $this->commentThreadLoader->load($coordinate, $eventHex !== '' ? $eventHex : null);
                ++$w;
            } catch (\Throwable $e) {
                $this->logger->warning('app:prewarm comment load', ['coord' => $coordinate, 'error' => $e->getMessage()]);
            }
        }
        $io->success(sprintf('Warmed comment cache for %d of %d article(s).', $w, \count($articles)));

        return Command::SUCCESS;
    }

    private function disableCliExecutionTimeLimit(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
    }
}
