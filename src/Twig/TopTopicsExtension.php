<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\TopicIndexService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TopTopicsExtension extends AbstractExtension
{
    public function __construct(
        private readonly TopicIndexService $topicIndexService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('top_topic_labels', function (int $limit = 25): array {
                return $this->topicIndexService->getTopTopicLabels($limit);
            }),
        ];
    }
}
