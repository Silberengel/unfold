<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Nostr\Nip10Kind1ArticleReplyTags;
use App\Nostr\Nip22CommentTags;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds {@see comment_reply_context} for article and publication comment UIs.
 */
final readonly class ArticleCommentReplyContextBuilder
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * } $data
     *
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>,
     *     comment_reply_context: array{
     *         can_publish: bool,
     *         coordinate: string,
     *         article_event_id: ?string,
     *         parent_kind: int,
     *         rows: array<int, array<string, mixed>>,
     *         fragment_url: string
     *     }
     * }
     */
    public function enrich(array $data, string $coordinate, ?string $articleEventId, string $articleTitle): array
    {
        $coordparts = explode(':', $coordinate, 3);
        $articleKind = ctype_digit($coordparts[0]) ? (int) $coordparts[0] : 30023;
        $articleAuthorPubkey = strtolower(trim((string) ($coordparts[1] ?? '')));

        $articleReplyTags = null;
        if ($articleAuthorPubkey !== '' && 64 === \strlen($articleAuthorPubkey) && ctype_xdigit($articleAuthorPubkey)) {
            $articleReplyTags = Nip22CommentTags::forReplyToArticle($coordinate, $articleAuthorPubkey);
        }

        $parentIdForNaddr = str_repeat('0', 64);
        if ($articleEventId !== null && 64 === \strlen($articleEventId) && ctype_xdigit($articleEventId)) {
            $articleParentId = $articleEventId;
        } else {
            $articleParentId = $parentIdForNaddr;
        }

        $threadReplyRows = [];
        $userMayReply = $this->security->isGranted('ROLE_USER');
        if ($userMayReply && $articleReplyTags !== null) {
            $threadReplyRows[] = [
                'mode' => 'article',
                'blurbLabel' => $articleTitle !== '' ? $articleTitle : 'Article',
                'parentKind' => $articleKind,
                'parentId' => $articleParentId,
                'authorPubkey' => $articleAuthorPubkey,
                'expectedTags' => $articleReplyTags,
            ];
        }

        if ($userMayReply) {
            /** @var array<int, object> $list */
            $list = $data['list'];
            foreach ($list as $row) {
                $k = (int) ($row->kind ?? 0);
                if ($k !== KindsEnum::COMMENTS->value && $k !== KindsEnum::TEXT_NOTE->value) {
                    continue;
                }
                $cid = strtolower(trim((string) ($row->id ?? '')));
                $cpk = strtolower(trim((string) ($row->pubkey ?? '')));
                if ($cid === '' || 64 !== \strlen($cid) || !ctype_xdigit($cid)) {
                    continue;
                }
                if ($cpk === '' || 64 !== \strlen($cpk) || !ctype_xdigit($cpk)) {
                    continue;
                }
                $rawTags = json_decode(json_encode($row->tags ?? []), true);
                if (!\is_array($rawTags)) {
                    $rawTags = [];
                }
                $forSnippet = (string) ($row->unfold_body ?? $row->content ?? '');
                $snippet = trim($forSnippet);
                if (strlen($snippet) > 120) {
                    $snippet = substr($snippet, 0, 117).'…';
                }
                if ($snippet === '') {
                    $snippet = 'Comment';
                }
                try {
                    if ($k === KindsEnum::COMMENTS->value) {
                        $expectedTags = Nip22CommentTags::forReplyToComment($cid, $cpk, $k, $rawTags);
                    } else {
                        $expectedTags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
                            $cid,
                            $cpk,
                            $rawTags,
                            $coordinate,
                            $articleEventId
                        );
                    }
                } catch (\Throwable) {
                    continue;
                }
                $threadReplyRows[] = [
                    'mode' => 'comment',
                    'blurbLabel' => $snippet,
                    'parentKind' => $k,
                    'parentId' => $cid,
                    'authorPubkey' => $cpk,
                    'expectedTags' => $expectedTags,
                ];
            }
        }

        $fragmentQuery = ['coordinate' => $coordinate, 'title' => $articleTitle];
        if ($articleEventId !== null) {
            $fragmentQuery['e'] = $articleEventId;
        }
        $data['comment_reply_context'] = [
            'can_publish' => $userMayReply,
            'coordinate' => $coordinate,
            'article_event_id' => $articleEventId,
            'parent_kind' => $articleKind,
            'rows' => $threadReplyRows,
            'fragment_url' => $this->urlGenerator->generate('article_comments_fragment', $fragmentQuery),
        ];

        return $data;
    }

    /**
     * @return array{
     *     can_publish: bool,
     *     coordinate: string,
     *     article_event_id: ?string,
     *     parent_kind: int,
     *     rows: array<int, array<string, mixed>>,
     *     fragment_url: string
     * }
     */
    public function buildArticleReplyContext(string $coordinate, ?string $articleEventId, string $articleTitle): array
    {
        $enriched = $this->enrich([
            'list' => [],
            'quotes' => [],
            'commentLinks' => [],
            'quoteLinks' => [],
            'processedContent' => [],
        ], $coordinate, $articleEventId, $articleTitle);

        return $enriched['comment_reply_context'];
    }
}
