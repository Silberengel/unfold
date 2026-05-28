<?php

namespace App\Enum;

enum KindsEnum: int
{
    case METADATA = 0; // metadata, NIP-01
    case DELETION_REQUEST = 5; // NIP-09
    case TEXT_NOTE = 1; // text note, NIP-01
    case REACTION = 7; // NIP-25, NIP-30 emoji in content
    case FOLLOWS = 3;
    case REPOST = 6; // Only wraps kind 1, NIP-18, will not implement
    case GENERIC_REPOST = 16; // Generic repost, original kind signalled in a "k" tag, NIP-18
    case FILE_METADATA = 1063; // NIP-94
    case COMMENTS = 1111;
    case HTTP_AUTH = 27235; // NIP-98, HTTP Auth
    case CURATION_SET = 30004; // NIP-51
    case LONGFORM = 30023; // NIP-23
    case LONGFORM_DRAFT = 30024; // NIP-23
    case WIKI = 30817; // NIP-54 wiki pages (Markdown)
    case PUBLICATION_INDEX = 30040; // NKBIP-01 index
    case PUBLICATION_CONTENT = 30041; // NKBIP-01 section (Asciidoc)
    case WIKI_ARTICLE = 30818; // NIP-54 wiki article (Asciidoc in publications)
    case ZAP_REQUEST = 9734; // NIP-57, Zap request
    case ZAP = 9735; // NIP-57, Zap receipt (Lightning)
    case MONERO_ZAP_RECEIPT = 9736; // Monero zap receipt (Garnet/Nosmero, analogous to 9735)
    case PAYMENT_NOTIFICATION = 9740; // NIP-A3, payment notification (superchat sender)
    case PAYMENT_ATTESTATION = 9741; // NIP-A3, payment attestation (superchat recipient confirms)
    case MONERO_TIP = 1814; // Garnet Monero tip (self-attesting, proof embedded in content JSON)
    case HIGHLIGHTS = 9802;
    case RELAY_LIST = 10002; // NIP-65, Relay list metadata
    case EMOJI_LIST = 10030; // NIP-51 standard list, NIP-30 emoji tags
    case PAYMENT_TARGETS = 10133; // NIP-A3, payto: payment targets (replaceable)
    case APP_DATA = 30078; // NIP-78, Arbitrary custom app data
    case USER_STATUS = 30315; // NIP-38, NIP-30 emoji in content

    /**
     * All kinds stored as long-form articles in the `article` table: 30023, 30024, 30817.
     *
     * @return list<self>
     */
    public static function longformKinds(): array
    {
        return [self::LONGFORM, self::LONGFORM_DRAFT, self::WIKI];
    }

    /**
     * @return list<int>
     */
    public static function longformKindValues(): array
    {
        return [self::LONGFORM->value, self::LONGFORM_DRAFT->value, self::WIKI->value];
    }

    /**
     * Valid `a` tags in magazine kind-30040 category indices only.
     *
     * @return list<int>
     */
    public static function magazineCategoryKindValues(): array
    {
        return [self::LONGFORM->value, self::LONGFORM_DRAFT->value, self::WIKI->value];
    }

    /**
     * Leaf section kinds inside a publication (NKBIP) 30040 index.
     *
     * @return list<int>
     */
    public static function publicationSectionKindValues(): array
    {
        return [
            self::LONGFORM->value,
            self::WIKI->value,
            self::PUBLICATION_CONTENT->value,
            self::WIKI_ARTICLE->value,
        ];
    }

    /**
     * All kinds persisted in `article` and renderable as body content.
     *
     * @return list<int>
     */
    public static function articleBodyKindValues(): array
    {
        return [
            self::LONGFORM->value,
            self::LONGFORM_DRAFT->value,
            self::WIKI->value,
            self::PUBLICATION_CONTENT->value,
            self::WIKI_ARTICLE->value,
        ];
    }

    /**
     * @return list<self>
     */
    public static function articleBodyKinds(): array
    {
        return [
            self::LONGFORM,
            self::LONGFORM_DRAFT,
            self::WIKI,
            self::PUBLICATION_CONTENT,
            self::WIKI_ARTICLE,
        ];
    }

    /**
     * @return list<int>
     */
    public static function markdownBodyKindValues(): array
    {
        return [self::LONGFORM->value, self::LONGFORM_DRAFT->value, self::WIKI->value];
    }

    /**
     * @return list<int>
     */
    public static function asciidocBodyKindValues(): array
    {
        return [self::PUBLICATION_CONTENT->value, self::WIKI_ARTICLE->value];
    }

    /**
     * Kinds included in global article search when publications are enabled for the tenant.
     *
     * @return list<int>
     */
    public static function searchableArticleKindValues(bool $publicationsEnabled): array
    {
        $kinds = [self::LONGFORM->value, self::LONGFORM_DRAFT->value, self::WIKI->value];
        if ($publicationsEnabled) {
            $kinds[] = self::WIKI_ARTICLE->value;
        }

        return $kinds;
    }

    /**
     * Kinds scraped in articles:get time-window when publications are enabled.
     *
     * @return list<int>
     */
    public static function relayBackfillKindValues(bool $publicationsEnabled): array
    {
        $kinds = self::longformKindValues();
        if (!$publicationsEnabled) {
            return $kinds;
        }

        return array_values(array_unique(array_merge(
            $kinds,
            [self::PUBLICATION_INDEX->value, self::PUBLICATION_CONTENT->value, self::WIKI_ARTICLE->value],
        )));
    }

    /**
     * Kinds that get rich in-content naddr preview cards.
     *
     * @return list<int>
     */
    public static function naddrPreviewCardKindValues(): array
    {
        return [
            self::LONGFORM->value,
            self::WIKI->value,
            self::PUBLICATION_INDEX->value,
            self::PUBLICATION_CONTENT->value,
            self::WIKI_ARTICLE->value,
        ];
    }
}
