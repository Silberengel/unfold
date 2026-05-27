<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ArticleHighlight;
use App\Repository\ArticleHighlightRepository;
use App\Repository\ArticleRepository;
use App\Service\ArticleBodyHighlightInjector;
use App\Util\CommonMark\Converter;
use App\Service\NostrKeyHelper;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run inside the app container, e.g.:
 * `php bin/console app:article-highlights-audit bitcoin-is-time --npub=npub1…`
 */
#[AsCommand(
    name: 'app:article-highlights-audit',
    description: 'Show how many kind-9802 rows match the article and how many <mark> injections succeed (debugging)',
)]
final class ArticleHighlightsAuditCommand extends Command
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly ArticleHighlightRepository $articleHighlightRepository,
        private readonly Converter $converter,
        private readonly ArticleBodyHighlightInjector $articleBodyHighlightInjector,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Article d-identifier (slug), e.g. bitcoin-is-time')
            ->addOption('npub', null, InputOption::VALUE_OPTIONAL, 'If set, must match the article author (npub1…)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        if ($slug === '') {
            $io->error('Empty slug.');

            return Command::FAILURE;
        }

        $article = $this->articleRepository->findLatestBySlug($slug);
        if (null === $article) {
            $io->error('No article row for this slug.');

            return Command::FAILURE;
        }

        $expectedNpub = $this->nostrKeyHelper->convertPublicKeyToBech32((string) $article->getPubkey());
        $optNpub = $input->getOption('npub');
        if (\is_string($optNpub) && $optNpub !== '') {
            if ($this->nostrKeyHelper->convertToHex($optNpub) !== strtolower((string) $article->getPubkey())) {
                $io->error('npub does not match this article’s author (expected: '.$expectedNpub.').');

                return Command::FAILURE;
            }
        }

        $io->title('Article highlights audit: '.$slug);
        $io->writeln('Author npub: <info>'.$expectedNpub.'</info>');
        $io->writeln('Article id: <info>'.(string) $article->getId().'</info> · kind: <info>'.
            $article->getKind()->value.'</info>');

        $highlights = $this->articleHighlightRepository->findByArticle($article);
        $io->writeln('Rows from <comment>findByArticle</comment>: <info>'.\count($highlights).'</info>');

        try {
            $html = $this->converter->convertToHTML((string) $article->getContent());
        } catch (CommonMarkException $e) {
            $io->error('CommonMark: '.$e->getMessage());

            return Command::FAILURE;
        }

        $out = $this->articleBodyHighlightInjector->inject($html, $highlights);
        $injected = $out['injectedEventIds'];
        $markCount = \substr_count($out['html'], 'user-highlight__marker');
        $io->writeln('Injected event ids with <comment>all highlights together</comment> (duplicates = same passage): <info>'.\count($injected).'</info>');
        $io->writeln('<mark class="user-highlight__marker"> count in body: <info>'.$markCount.'</info>');

        $io->section('Each highlight in isolation (same HTML, one 9802 at a time)');
        $rows = [];
        $isolatedOk = 0;
        foreach ($highlights as $h) {
            $eid = \strtolower($h->getEventId());
            $one = $this->articleBodyHighlightInjector->inject($html, [$h]);
            $found = 1 === \preg_match(
                '/\bid=([\'"])highlight-'.preg_quote($eid, '/').'\1/i',
                $one['html']
            );
            if ($found) {
                ++$isolatedOk;
            }
            $snippet = $this->excerptOneLine((string) $h->getContent(), 72);
            $rows[] = [
                $found ? 'yes' : 'no',
                $eid,
                $snippet,
            ];
        }
        $io->table(['Match', 'event id', 'stored `content` (excerpt)'], $rows);
        if ($isolatedOk < \count($highlights)) {
            $io->writeln(
                '‘Match: no’ means the stored passage is absent from the flattened body text, or it diverges '.
                '(soft hyphens, smart quotes, edits, footnotes, etc.). Re-sync kind 9802 from relays, or adjust matching in ArticleBodyHighlightInjector.'
            );
        }

        if ($markCount < 1 && \count($highlights) > 0) {
            $io->warning('With all highlights together, nothing was injected. Per-row check above still shows if any row matches in isolation.');
        } elseif (\count($highlights) < 1) {
            $io->note('No article_highlight rows for this slug+author. Run prewarm highlight sync or check MySQL.');
        } elseif ($markCount > 0) {
            $io->success('At least one <mark> was produced when all rows were passed to the injector together.');
        }

        if ($io->isVerbose() && $injected !== []) {
            $io->section('Injected event ids (batch, may include several per passage)');
            $io->listing($injected);
        }

        return Command::SUCCESS;
    }

    /**
     * One line for the table: reflect {@see ArticleHighlight::getContent()} bytes faithfully.
     * Only line breaks are folded to a space so the row stays one line — we do not collapse
     * {@see \p{Z}} or remove U+00AD (soft hyphen); doing that made passages look like they
     * contained ASCII spaces the Nostr `content` never had.
     */
    private function excerptOneLine(string $s, int $max): string
    {
        $s = (string) \preg_replace('/\R/u', ' ', $s);
        if (\mb_strlen($s, 'UTF-8') > $max) {
            $s = \mb_substr($s, 0, $max - 1, 'UTF-8').'…';
        }

        return $s;
    }
}
