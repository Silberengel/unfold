<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\KindsEnum;
use App\Service\MercuryHttpRelayClient;
use App\Service\NostrRelayFanoutTransport;
use App\Service\NostrRelayListFactory;
use App\Service\NostrRelayRequestFactory;
use App\Service\NostrRelayQuery;
use App\Service\NostrRelayTransport;
use App\Service\RelayFetchedEventPersister;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        $persister = $this->createMock(RelayFetchedEventPersister::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $listFactory = new NostrRelayListFactory([], [], [], $tokenStorage, new NullLogger());
        $fanout = new NostrRelayFanoutTransport(new NullLogger(), $factory, \dirname(__DIR__, 2));
        $mercury = new MercuryHttpRelayClient(new MockHttpClient(), new NullLogger(), 12);
        $transport = new NostrRelayTransport($listFactory, $fanout, $mercury);
        $q = new NostrRelayQuery(new NullLogger(), $factory, $persister, $transport);
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
