<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use App\Service\CacheService;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class NostrSchemeExtension  implements ExtensionInterface
{

    public function __construct(
        private readonly CacheService $cacheService,
        private readonly Nip19Codec $nip19,
    ) {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrBareBech32Parser($this->nip19), 202)
            ->addInlineParser(new NostrMentionParser($this->cacheService), 200)
            ->addInlineParser(new NostrSchemeParser($this->nip19), 199)
            ->addInlineParser(new NostrRawNpubParser(), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer($this->nip19), 2)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer($this->cacheService), 1)
        ;
    }
}
