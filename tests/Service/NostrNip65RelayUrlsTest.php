<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrNip65RelayUrls;
use PHPUnit\Framework\TestCase;

final class NostrNip65RelayUrlsTest extends TestCase
{
    public function testCollectsWssAndDropsLocalhostAndNonWss(): void
    {
        $s = new NostrNip65RelayUrls();
        $wire = (object) [
            'tags' => [
                ['r', 'wss://relay.example.com'],
                ['r', 'wss://localhost:8080'],
                ['r', 'http://not-tls.example.com'],
                ['p', 'ignored'],
            ],
        ];
        $out = $s->wssListFromKind10002Wire($wire);
        $this->assertSame(['wss://relay.example.com'], $out);
    }

    public function testOutboxIncludesWriteAndUnmarkedOnly(): void
    {
        $s = new NostrNip65RelayUrls();
        $wire = (object) [
            'tags' => [
                ['r', 'wss://read-only', 'read'],
                ['r', 'wss://write-relay', 'write'],
                ['r', 'wss://legacy-relay'],
                ['r', 'wss://localhost:1', 'write'],
            ],
        ];
        $all = $s->wssListFromKind10002Wire($wire);
        $outbox = $s->outboxWssListFromKind10002Wire($wire);
        $this->assertSame(
            ['wss://read-only', 'wss://write-relay', 'wss://legacy-relay'],
            $all,
        );
        $this->assertSame(
            ['wss://write-relay', 'wss://legacy-relay'],
            $outbox,
        );
    }
}
