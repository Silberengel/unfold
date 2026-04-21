<?php

namespace App\Util\CommonMark\ImagesExtension;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class RawImageLinkExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        // UrlAutolinkParser uses default priority 0; run first so GIF/JPEG URLs become <img>, not <a>.
        $environment->addInlineParser(new RawImageLinkParser(), 1000);
    }
}
