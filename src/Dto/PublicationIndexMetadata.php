<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Kind-30040 publication index metadata for reader UI (NKBIP-01 / curated publications).
 */
final readonly class PublicationIndexMetadata
{
    /**
     * @param list<string>              $authors          `author` tags (display names)
     * @param list<string>              $nostrAuthorHexes `p` tags (64-hex pubkeys, tag order)
     * @param list<string>              $topics           `t` tags
     * @param list<string>              $sources          `source` tags (URLs or labels)
     * @param list<string>              $identifiers      `i` tags (e.g. isbn:…)
     * @param list<string>              $publishedOn      `published_on` tags
     * @param list<string>              $publishedBy      `published_by` tags (human publisher name)
     */
    public function __construct(
        public string $publisherPubkeyHex,
        public string $publisherNpub,
        public array $authors,
        public array $nostrAuthorHexes,
        public array $topics,
        public array $sources,
        public array $identifiers,
        public ?string $type,
        public ?string $version,
        public array $publishedOn,
        public array $publishedBy,
        public string $summary,
        public ?string $autoUpdate,
        public int $eventCreatedAt,
    ) {
    }
}
