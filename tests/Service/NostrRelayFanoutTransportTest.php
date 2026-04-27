<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrRelayFanoutTransport;
use App\Service\NostrRelayRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NostrRelayFanoutTransportTest extends TestCase
{
    public function testCapUrlsForSequentialLeavesShortListsUnchanged(): void
    {
        $t = $this->makeTransport();
        $in = ['wss://a', 'wss://b'];
        $this->assertSame($in, $t->capUrlsForSequential($in));
    }

    public function testCapUrlsForSequentialTrimsToThreeRelays(): void
    {
        $t = $this->makeTransport();
        $in = ['wss://1', 'wss://2', 'wss://3', 'wss://4'];
        $out = $t->capUrlsForSequential($in);
        $this->assertSame(['wss://1', 'wss://2', 'wss://3'], $out);
    }

    private function makeTransport(): NostrRelayFanoutTransport
    {
        return new NostrRelayFanoutTransport(
            new NullLogger(),
            new NostrRelayRequestFactory(10),
            \sys_get_temp_dir()
        );
    }
}
