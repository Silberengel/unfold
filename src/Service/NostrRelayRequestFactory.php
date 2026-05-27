<?php

declare(strict_types=1);

namespace App\Service;

use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;

/**
 * Builds swentel {@see Request} instances with per-relay I/O timeout (config: `nostr_relay_request_timeout_sec`).
 * Shared by {@see NostrClient} so request wiring stays in one place.
 */
final readonly class NostrRelayRequestFactory
{
    public function __construct(
        private int $relayRequestTimeoutSec = 12,
    ) {
    }

    public function getRelayRequestTimeoutSec(): int
    {
        return $this->relayRequestTimeoutSec;
    }

    /**
     * {@see Request::setTimeout()} drives per-relay WebSocket I/O for {@see Request::send()}.
     *
     * @param int|null $overrideTimeoutSec when set, uses this instead of the configured default
     */
    public function createTimedRequest(RelaySet $relaySet, RequestMessage $requestMessage, ?int $overrideTimeoutSec = null): Request
    {
        $request = new Request($relaySet, $requestMessage);

        return $request->setTimeout($overrideTimeoutSec ?? $this->relayRequestTimeoutSec);
    }

    /**
     * For paths that use {@see RelaySet::send()} with a custom message and bypass {@see Request}.
     */
    public function applySocketTimeoutToRelaySet(RelaySet $relaySet): void
    {
        foreach ($relaySet->getRelays() as $relay) {
            $client = $relay->getClient();
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout($this->relayRequestTimeoutSec);
            }
        }
    }
}
