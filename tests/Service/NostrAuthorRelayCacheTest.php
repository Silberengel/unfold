<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrAuthorRelayCache;
use App\Service\NostrClient;
use App\Service\NostrRelayListFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NostrAuthorRelayCacheTest extends TestCase
{
    public function testGetAuthorNip65RelaysListDedupesAndCaches(): void
    {
        $pk = str_repeat('b', 64);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects($this->once())
            ->method('getNpubRelays')
            ->with($pk)
            ->willReturn(['wss://r1', 'wss://r2', 'wss://r1', 'http://ignored', 'wss://localhost:1']);

        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $listFactory = new NostrRelayListFactory(['wss://default'], [], [], $ts, new NullLogger());

        $c = new NostrAuthorRelayCache(
            new ArrayAdapter(),
            new NullLogger(),
            $listFactory,
            $nostr
        );

        $this->assertSame(['wss://r1', 'wss://r2'], $c->getAuthorNip65RelaysList($pk));
        $this->assertSame(['wss://r1', 'wss://r2'], $c->getAuthorNip65RelaysList($pk), 'second call should use cache, not re-fetch');
    }

    public function testGetTopReputableRelaysForAuthorFallsBackToDefaultRelayWhenEmpty(): void
    {
        $pk = str_repeat('c', 64);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->method('getNpubRelays')->willReturn([]);

        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $listFactory = new NostrRelayListFactory(['wss://main'], [], [], $ts, new NullLogger());

        $c = new NostrAuthorRelayCache(
            new ArrayAdapter(),
            new NullLogger(),
            $listFactory,
            $nostr
        );

        $this->assertSame(['wss://main'], $c->getTopReputableRelaysForAuthor($pk, 2));
    }
}
