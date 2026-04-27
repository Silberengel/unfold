<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrRelayRequestFactory;
use PHPUnit\Framework\TestCase;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Subscription\Subscription;

final class NostrRelayRequestFactoryTest extends TestCase
{
    public function testExposesConfiguredTimeout(): void
    {
        $f = new NostrRelayRequestFactory(21);
        $this->assertSame(21, $f->getRelayRequestTimeoutSec());
    }

    public function testCreateTimedRequestReturnsWiredRequest(): void
    {
        $f = new NostrRelayRequestFactory(12);
        $sub = new Subscription();
        $msg = new RequestMessage($sub->getId(), []);
        $set = new RelaySet();
        $set->addRelay(new Relay('wss://127.0.0.1:0'));

        $req = $f->createTimedRequest($set, $msg);
        $this->assertInstanceOf(\swentel\nostr\Request\Request::class, $req);
    }
}
