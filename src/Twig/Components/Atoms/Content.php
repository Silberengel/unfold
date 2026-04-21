<?php

namespace App\Twig\Components\Atoms;

use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Content
{
    public string $parsed = '';
    public function __construct(
        private readonly Converter $converter
    ) {
    }

    /**
     */
    public function mount($content): void
    {
        $raw = $content ?? '';
        if (!\is_string($raw)) {
            $raw = (string) $raw;
        }
        try {
            $this->parsed = $this->converter->convertToHtml($raw);
        } catch (CommonMarkException) {
            $this->parsed = $raw;
        }
    }
}
