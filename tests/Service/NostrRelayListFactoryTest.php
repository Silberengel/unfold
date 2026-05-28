<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrRelayListFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NostrRelayListFactoryTest extends TestCase
{
    public function testGetSearchRelayUrlListDeduplicates(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            'wss://forest',
            ['wss://forest', 'wss://citadel'],
            ['wss://profile'],
            $tokenStorage,
            new NullLogger(),
        );
        $this->assertSame(['wss://forest', 'wss://citadel'], $f->getSearchRelayUrlList());
    }

    public function testGetCommunityRelayUrl(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory('wss://forest', [], [], $ts, new NullLogger());
        $this->assertSame('wss://forest', $f->getCommunityRelayUrl());
        $this->assertSame(['wss://forest'], $f->getCommunityRelayUrlList());
    }

    public function testGetPublishRelayUrlListMergesCommunityAndSearch(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            'wss://forest',
            ['wss://citadel'],
            [],
            $ts,
            new NullLogger(),
        );
        $this->assertSame(['wss://forest', 'wss://citadel'], $f->getPublishRelayUrlList());
    }

    public function testIsTenantConfiguredRelay(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            'wss://forest',
            ['wss://citadel'],
            ['wss://profile'],
            $ts,
            new NullLogger(),
        );
        $this->assertTrue($f->isTenantConfiguredRelay('wss://forest/'));
        $this->assertTrue($f->isTenantConfiguredRelay('wss://profile'));
        $this->assertFalse($f->isTenantConfiguredRelay('wss://other'));
    }
}
