<?php

declare(strict_types=1);

namespace App\Service;

use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;

/**
 * Scheme-aware relay REQ dispatcher: wss via {@see NostrRelayFanoutTransport}, http via {@see MercuryHttpRelayClient}.
 */
final readonly class NostrRelayTransport
{
    public function __construct(
        private NostrRelayListFactory $relayListFactory,
        private NostrRelayFanoutTransport $relayFanout,
        private MercuryHttpRelayClient $mercuryClient,
    ) {
    }

    /**
     * @param list<string> $relayUrls
     *
     * @return array<string, mixed> Same shape as {@see \swentel\nostr\Request\Request::send()}
     */
    public function sendToUrls(array $relayUrls, RequestMessage $requestMessage, ?int $overrideTimeoutSec = null): array
    {
        $partition = $this->relayListFactory->partitionRelayUrlsByScheme($relayUrls);
        $merged = [];
        if ($partition['wss'] !== []) {
            $relaySet = $this->relayListFactory->relaySetFromDistinctUrlList($partition['wss']);
            $merged = array_replace($merged, $this->relayFanout->sendSequential($relaySet, $requestMessage, $overrideTimeoutSec));
        }
        if ($partition['http'] !== []) {
            $merged = array_replace($merged, $this->mercuryClient->queryRequestMessage($partition['http'], $requestMessage, $overrideTimeoutSec));
        }

        return $merged;
    }

    /**
     * Parallel wss workers plus in-process HTTP queries.
     *
     * @param list<string> $relayUrls
     *
     * @return array<string, mixed>
     */
    public function sendParallelToUrls(array $relayUrls, RequestMessage $requestMessage, ?int $overrideTimeoutSec = null): array
    {
        $partition = $this->relayListFactory->partitionRelayUrlsByScheme($relayUrls);
        $merged = [];
        if ($partition['wss'] !== []) {
            if (\count($partition['wss']) <= 1) {
                $relaySet = $this->relayListFactory->relaySetFromDistinctUrlList($partition['wss']);
                $merged = array_replace($merged, $this->relayFanout->sendSequential($relaySet, $requestMessage, $overrideTimeoutSec));
            } else {
                $merged = array_replace($merged, $this->relayFanout->sendParallelWorkers($partition['wss'], $requestMessage));
            }
        }
        if ($partition['http'] !== []) {
            $merged = array_replace($merged, $this->mercuryClient->queryRequestMessage($partition['http'], $requestMessage, $overrideTimeoutSec));
        }

        return $merged;
    }

    /**
     * @param list<string> $relayUrls
     */
    public function relaySetForWssUrls(array $relayUrls): RelaySet
    {
        return $this->relayListFactory->relaySetFromDistinctUrlList(
            $this->relayListFactory->partitionRelayUrlsByScheme($relayUrls)['wss'],
        );
    }
}
