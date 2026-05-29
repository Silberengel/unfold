<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrRelayListFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NostrRelayListFactoryTest extends TestCase
{
    public function testGetSearchRelayUrlListDeduplicatesAndKeepsHttp(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            ['wss://forest'],
            ['wss://forest', 'wss://citadel', 'http://mercury.example'],
            ['wss://profile'],
            ['wss://damus', 'wss://nos'],
            $tokenStorage,
            new NullLogger(),
        );
        $this->assertSame(
            ['wss://forest', 'wss://citadel', 'http://mercury.example'],
            $f->getSearchRelayUrlList(),
        );
        $this->assertSame(['wss://forest', 'wss://citadel'], $f->getSearchWssUrlList());
        $this->assertSame(['http://mercury.example'], $f->getSearchHttpUrlList());
        $this->assertSame(
            ['wss://damus', 'wss://nos'],
            $f->getNip46ClientRelayUrlList(),
        );
    }

    public function testGetCommunityRelayUrl(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(['wss://forest'], [], [], [], $ts, new NullLogger());
        $this->assertSame('wss://forest', $f->getCommunityRelayUrl());
        $this->assertSame(['wss://forest'], $f->getCommunityRelayUrlList());
    }

    public function testGetPublishRelayUrlListMergesCommunityAndSearch(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            ['wss://forest', 'http://mercury.example'],
            ['wss://citadel'],
            [],
            [],
            $ts,
            new NullLogger(),
        );
        $this->assertSame(
            ['wss://forest', 'http://mercury.example', 'wss://citadel'],
            $f->getPublishRelayUrlList(),
        );
    }

    public function testPartitionRelayUrlsByScheme(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory([], [], [], [], $ts, new NullLogger());
        $this->assertSame(
            [
                'wss' => ['wss://a', 'wss://b'],
                'http' => ['https://mercury.example'],
            ],
            $f->partitionRelayUrlsByScheme(['wss://a', 'https://mercury.example', 'wss://b', 'wss://a']),
        );
    }

    public function testProfileMetadataQueryUsesWssOnly(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            ['wss://forest', 'http://mercury.example'],
            ['wss://citadel', 'http://mercury.example'],
            ['wss://profile'],
            [],
            $ts,
            new NullLogger(),
        );
        $this->assertSame(
            ['wss://profile', 'wss://forest', 'wss://citadel'],
            $f->getProfileMetadataQueryRelayUrlList(),
        );
    }

    public function testIsTenantConfiguredRelay(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            ['wss://forest'],
            ['wss://citadel', 'http://mercury.example'],
            ['wss://profile'],
            [],
            $ts,
            new NullLogger(),
        );
        $this->assertTrue($f->isTenantConfiguredRelay('wss://forest/'));
        $this->assertTrue($f->isTenantConfiguredRelay('http://mercury.example'));
        $this->assertTrue($f->isTenantConfiguredRelay('wss://profile'));
        $this->assertFalse($f->isTenantConfiguredRelay('wss://other'));
    }
}
