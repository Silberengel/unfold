<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\KindsEnum;
use App\Service\NostrRelayRequestFactory;
use App\Service\NostrRelayQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;

final class NostrRelayQueryTest extends TestCase
{
    public function testRelayLogLabelUsesHost(): void
    {
        $this->assertSame(
            'relay.example.com',
            NostrRelayQuery::relayLogLabel('wss://relay.example.com/nostr')
        );
    }

    public function testCreateNostrRequestAcceptsBackedEnumKinds(): void
    {
        $factory = new NostrRelayRequestFactory(12);
        $q = new NostrRelayQuery(new NullLogger(), $factory);
        $set = new RelaySet();
        $set->addRelay(new Relay('wss://127.0.0.1:0'));
        $req = $q->createNostrRequest(
            defaultRelaySet: $set,
            kinds: [KindsEnum::METADATA],
            filters: [],
        );
        $this->assertInstanceOf(\swentel\nostr\Request\Request::class, $req);
    }
}
