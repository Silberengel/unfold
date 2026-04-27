<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrRelayListFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NostrRelayListFactoryTest extends TestCase
{
    public function testGetConfiguredArticleRelayUrlListDeduplicatesAndPreservesOrder(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory(
            'wss://main',
            ['wss://extra', 'wss://main', 'wss://extra'],
            ['wss://profile'],
            $tokenStorage,
            new NullLogger()
        );
        $this->assertSame(['wss://main', 'wss://extra'], $f->getConfiguredArticleRelayUrlList());
    }

    public function testGetDefaultRelayUrl(): void
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $f = new NostrRelayListFactory('wss://d', [], [], $ts, new NullLogger());
        $this->assertSame('wss://d', $f->getDefaultRelayUrl());
    }
}
