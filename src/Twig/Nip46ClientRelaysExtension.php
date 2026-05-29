<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\NostrRelayListFactory;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class Nip46ClientRelaysExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly NostrRelayListFactory $relayListFactory,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'nip46_client_relays' => $this->relayListFactory->getNip46ClientRelayUrlList(),
        ];
    }
}
