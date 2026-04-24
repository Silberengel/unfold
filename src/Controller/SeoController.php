<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Repository\ArticleRepository;
use App\Repository\FeaturedAuthorRepository;
use App\Service\MagazineContentService;
use App\Service\MagazineIndexStore;
use App\Service\NostrPathHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sitemap, robots.txt, and Atom feeds for the magazine and each category.
 */
final class SeoController extends AbstractController
{
    private const FEED_MAX_ITEMS = 100;

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly MagazineContentService $magazineContent,
        private readonly MagazineIndexStore $magazineIndexStore,
        private readonly ParameterBagInterface $params,
        private readonly FeaturedAuthorRepository $featuredAuthorRepository,
        private readonly NostrPathHelper $nostrPathHelper,
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [];

        $urls[] = ['loc' => $this->absoluteUrlForRoute('home'), 'lastmod' => null];

        if ((bool) $this->params->get('community_articles')) {
            $urls[] = ['loc' => $this->absoluteUrlForRoute('articles'), 'lastmod' => null];
        }

        $urls[] = ['loc' => $this->absoluteUrlForRoute('featured_authors'), 'lastmod' => null];

        foreach ($this->magazineContent->getCategorySlugsFromStore() as $slug) {
            $urls[] = [
                'loc' => $this->absoluteUrlForRoute('magazine-category', ['slug' => $slug]),
                'lastmod' => null,
            ];
        }

        $articles = $this->articleRepository->findPublishedForSyndication(8000);
        $bySlug = $this->dedupeArticlesByLatestRevision($articles);
        foreach ($bySlug as $article) {
            $loc = $this->nostrPathHelper->articleAbsoluteUrl($article);
            if ($loc === '') {
                continue;
            }
            $urls[] = [
                'loc' => $loc,
                'lastmod' => $this->articleLastMod($article),
            ];
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            ."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $row) {
            $body .= "\n  <url>\n    <loc>".$this->xmlText($row['loc']).'</loc>';
            if ($row['lastmod'] instanceof \DateTimeInterface) {
                $body .= "\n    <lastmod>".$row['lastmod']->format('Y-m-d').'</lastmod>';
            }
            $body .= "\n  </url>";
        }
        $body .= "\n</urlset>\n";

        return $this->xmlResponse($body);
    }

    #[Route('/robots.txt', name: 'robots_txt', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->absoluteUrlForRoute('sitemap');
        $txt = "User-agent: *\nAllow: /\n\nSitemap: {$sitemap}\n";

        return new Response(
            $txt,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    /**
     * NIP-05 well-known: maps site-assigned local-parts to hex pubkeys (featured magazine authors).
     * Must not redirect. Includes recommended `relays` for clients when profile relay URLs are configured.
     */
    #[Route(path: '/.well-known/nostr.json', name: 'nostr_well_known', methods: ['GET', 'HEAD'])]
    public function nostrWellKnown(): JsonResponse
    {
        $rows = $this->featuredAuthorRepository->findAllListedOrderByLocalPart();
        $names = [];
        foreach ($rows as $r) {
            $names[$r->getLocalPart()] = strtolower($r->getPubkeyHex());
        }
        $payload = ['names' => $names];
        $relays = $this->buildRelaysByPubkey($names);
        if ($relays !== []) {
            $payload['relays'] = $relays;
        }

        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=120',
        ];

        return new JsonResponse(
            $payload,
            Response::HTTP_OK,
            $headers
        );
    }

    /**
     * @param array<string, string> $names local-part => hex pubkey
     *
     * @return array<string, list<string>>
     */
    private function buildRelaysByPubkey(array $names): array
    {
        $raw = $this->params->get('profile_relays');
        if (!\is_array($raw) || $raw === []) {
            return [];
        }
        $urls = [];
        foreach ($raw as $u) {
            if (\is_string($u) && (str_starts_with($u, 'wss://') || str_starts_with($u, 'ws://'))) {
                $urls[] = $u;
            }
        }
        if ($urls === []) {
            return [];
        }
        $out = [];
        foreach ($names as $hex) {
            $out[strtolower($hex)] = $urls;
        }

        return $out;
    }

    #[Route('/feeds/magazine.xml', name: 'feed_magazine', methods: ['GET'])]
    public function feedMagazine(Request $request): Response
    {
        $site = (string) $this->params->get('name');
        $list = $this->magazineContent->getAllMagazineCategoryArticlesForSyndication();
        $list = \array_slice($list, 0, self::FEED_MAX_ITEMS);
        $feedUrl = $this->absoluteUrlForRoute('feed_magazine');
        $homeUrl = $this->absoluteUrlForRoute('home');
        $selfId = 'urn:web:'.$this->urlHostId($request).':feed:magazine';
        $updated = $this->newestArticleUpdate($list);

        $body = $this->buildAtomFeed(
            $site.': all categories',
            (string) $this->params->get('description'),
            $selfId,
            $feedUrl,
            $homeUrl,
            $updated,
            $request,
            $list,
        );

        return $this->atomResponse($body);
    }

    #[Route('/feeds/cat/{slug}.xml', name: 'feed_category', methods: ['GET'])]
    public function feedCategory(Request $request, string $slug): Response
    {
        if ($this->magazineIndexStore->getCategory($slug) === null) {
            throw $this->createNotFoundException('Unknown category');
        }
        $site = (string) $this->params->get('name');
        $data = $this->magazineContent->getCategoryPageData($slug);
        $rawList = $data['list'] ?? [];
        $catTitle = (string) ($data['category']['title'] ?? $this->magazineContent->getCategoryDisplayTitle($slug));
        $summary = (string) ($data['category']['summary'] ?? '');

        $list = array_values(
            array_filter(
                $rawList,
                static function (Article $a): bool {
                    $s = $a->getEventStatus();
                    if ($s === null) {
                        return false;
                    }

                    return $s === EventStatusEnum::PUBLISHED || $s === EventStatusEnum::ARCHIVED;
                }
            )
        );
        if (\count($list) > self::FEED_MAX_ITEMS) {
            $list = \array_slice($list, 0, self::FEED_MAX_ITEMS);
        }
        $feedUrl = $this->absoluteUrlForRoute('feed_category', ['slug' => $slug]);
        $categoryPage = $this->absoluteUrlForRoute('magazine-category', ['slug' => $slug]);
        $selfId = 'urn:web:'.$this->urlHostId($request).':feed:cat:'.rawurlencode($slug);
        $title = $catTitle !== '' ? $catTitle.' — '.$site : $site;
        $subtitle = $summary !== '' ? $summary : (string) $this->params->get('description');
        $updated = $this->newestArticleUpdate($list);

        $body = $this->buildAtomFeed(
            $title,
            $subtitle,
            $selfId,
            $feedUrl,
            $categoryPage,
            $updated,
            $request,
            $list,
        );

        return $this->atomResponse($body);
    }

    private function absoluteUrlForRoute(string $name, array $params = []): string
    {
        return $this->generateUrl($name, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function urlHostId(Request $request): string
    {
        $h = $request->getHost();

        return preg_replace('/[^a-zA-Z0-9.\\-]+/', '-', $h) ?? 'site';
    }

    /**
     * @param list<Article> $list
     */
    private function buildAtomFeed(
        string $title,
        string $subtitle,
        string $id,
        string $selfUrl,
        string $alternateHtmlUrl,
        \DateTimeImmutable $updated,
        Request $request,
        array $list,
    ): string {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            ."\n"
            .'<feed xmlns="http://www.w3.org/2005/Atom">'
            ."\n  <title>".$this->xmlText($title)."</title>\n  <subtitle>".$this->xmlText($subtitle)."</subtitle>";
        $xml .= "\n  <id>".$this->xmlText($id).'</id>';
        $xml .= "\n  <link href=\"".$this->xmlAttr($selfUrl)."\" rel=\"self\" type=\"application/atom+xml\"/>";
        $xml .= "\n  <link href=\"".$this->xmlAttr($alternateHtmlUrl)."\" rel=\"alternate\" type=\"text/html\"/>";
        $xml .= "\n  <updated>".$this->xmlText($updated->format('c')).'</updated>';
        $authorName = (string) $this->params->get('name');
        $xml .= "\n  <author><name>".$this->xmlText($authorName)."</name></author>\n  <generator uri=\"https://github.com/decent-newsroom/unfold\" version=\"1\">unfold</generator>";
        foreach ($list as $article) {
            $xml .= $this->atomEntryForArticle($request, $article);
        }
        $xml .= "\n</feed>\n";

        return $xml;
    }

    private function atomEntryForArticle(Request $request, Article $article): string
    {
        $slug = \trim((string) $article->getSlug());
        if ($slug === '') {
            return '';
        }
        $permalink = $this->nostrPathHelper->articleAbsoluteUrl($article);
        if ($permalink === '') {
            return '';
        }
        $title = (string) ($article->getTitle() ?? 'Untitled');
        $tArticle = $this->articleLastMod($article);
        $sum = (string) ($article->getSummary() ?? '');
        if ($sum === '' && $article->getContent() !== null) {
            $plain = preg_replace('/\s+/', ' ', (string) $article->getContent()) ?? '';
            $sum = (string) mb_substr($plain, 0, 500);
        }
        // One stable Atom <id> per row. Nostr eventId can repeat (revisions, duplicates); readers
        // merge on <id> and would only show a single entry if ids collided.
        $dbId = $article->getId();
        $entryId = 'urn:web:'.$this->urlHostId($request)
            .':db-article:'.($dbId !== null && $dbId !== '' ? (string) $dbId : \spl_object_id($article));

        $pub = $article->getPublishedAt() ?? $article->getCreatedAt() ?? $tArticle;
        $out = "\n  <entry>";
        $out .= "\n    <title>".$this->xmlText($title)."</title>";
        $out .= "\n    <link href=\"".$this->xmlAttr($permalink)."\" rel=\"alternate\" type=\"text/html\"/>";
        $out .= "\n    <id>".$this->xmlText($entryId).'</id>';
        $out .= "\n    <updated>".$this->xmlText($tArticle->format('c'))."</updated>\n    <published>".$this->xmlText($pub->format('c')).'</published>';
        $out .= "\n    <summary type=\"text\">".$this->xmlText($this->oneLine($sum))."</summary>";
        $out .= "\n  </entry>";

        return $out;
    }

    private function oneLine(string $s): string
    {
        return trim(preg_replace("/[\r\n]+/", ' ', $s) ?? '');
    }

    /**
     * @param list<Article> $articles
     * @return array<string, Article>
     */
    private function dedupeArticlesByLatestRevision(array $articles): array
    {
        $bySlug = [];
        foreach ($articles as $article) {
            $slug = \trim((string) $article->getSlug());
            if ($slug === '') {
                continue;
            }
            $c = $article->getCreatedAt();
            if (!isset($bySlug[$slug])) {
                $bySlug[$slug] = $article;

                continue;
            }
            $prev = $bySlug[$slug]->getCreatedAt();
            if ($c !== null && (null === $prev || $c > $prev)) {
                $bySlug[$slug] = $article;
            }
        }

        return $bySlug;
    }

    /**
     * @param list<Article> $list
     */
    private function newestArticleUpdate(array $list): \DateTimeImmutable
    {
        $t = new \DateTimeImmutable('@0');
        foreach ($list as $a) {
            $m = $this->articleLastMod($a);
            if ($m > $t) {
                $t = $m;
            }
        }
        if ((int) $t->format('U') === 0) {
            return new \DateTimeImmutable();
        }

        return $t;
    }

    private function articleLastMod(Article $a): \DateTimeImmutable
    {
        $p = $a->getPublishedAt();
        $c = $a->getCreatedAt() ?? $p;
        if ($p !== null && $c !== null) {
            return $p > $c ? $p : $c;
        }

        return $p ?? $c ?? new \DateTimeImmutable();
    }

    private function xmlText(string $s): string
    {
        return htmlspecialchars($this->stripInvalidXml1Chars($s), \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    private function xmlAttr(string $s): string
    {
        return htmlspecialchars($this->stripInvalidXml1Chars($s), \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /**
     * XML 1.0 disallows C0 control chars other than tab, CR, LF; they can make feeds appear truncated
     * after the first entry that used only “clean” text.
     */
    private function stripInvalidXml1Chars(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    }

    private function xmlResponse(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=600',
            ],
        );
    }

    private function atomResponse(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/atom+xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }
}
