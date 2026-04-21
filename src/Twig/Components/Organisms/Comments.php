<?php

namespace App\Twig\Components\Organisms;

use App\Service\ArticleCommentThreadLoader;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Comments
{
    public array $list = [];

    public array $commentLinks = [];

    public array $processedContent = [];

    public function __construct(private readonly ArticleCommentThreadLoader $commentThreadLoader)
    {
    }

    public function mount($current): void
    {
        $data = $this->commentThreadLoader->load((string) $current);
        $this->list = $data['list'];
        $this->commentLinks = $data['commentLinks'];
        $this->processedContent = $data['processedContent'];
    }
}
