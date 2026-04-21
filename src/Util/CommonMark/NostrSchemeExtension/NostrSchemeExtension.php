<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\CacheService;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class NostrSchemeExtension  implements ExtensionInterface
{

    public function __construct(private readonly CacheService $cacheService)
    {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrBareBech32Parser(), 202)
            ->addInlineParser(new NostrMentionParser($this->cacheService), 200)
            ->addInlineParser(new NostrSchemeParser(), 199)
            ->addInlineParser(new NostrRawNpubParser($this->cacheService), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer(), 2)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer(), 1)
        ;
    }
}
