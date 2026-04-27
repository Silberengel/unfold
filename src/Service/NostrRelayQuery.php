<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

/**
 * NIP-01 style REQ construction and per-relay response iteration (EVENT / ERROR / …).
 * Extracted from {@see NostrClient} for reuse; logging stays on this service.
 */
final readonly class NostrRelayQuery
{
    public function __construct(
        private LoggerInterface $logger,
        private NostrRelayRequestFactory $relayRequestFactory,
    ) {
    }

    /**
     * Short host/URL for logs (e.g. fragment comments / prewarm) without full wss:// noise.
     */
    public static function relayLogLabel(string $relayUrl): string
    {
        $host = parse_url($relayUrl, \PHP_URL_HOST);
        if (\is_string($host) && $host !== '') {
            return $host;
        }

        return $relayUrl;
    }

    /**
     * @param list<int|\BackedEnum> $kinds Integers or PHP 8.1 enums backed by int (e.g. {@see \App\Enum\KindsEnum})
     * @param array<string, mixed> $filters Filter builder keys (e.g. authors, ids, tag, limit, …)
     */
    public function createNostrRequest(
        RelaySet $defaultRelaySet,
        ?RelaySet $relaySet = null,
        array $kinds = [],
        array $filters = [],
    ): Request {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $kindInts = [];
        foreach ($kinds as $k) {
            $kindInts[] = $k instanceof \BackedEnum ? (int) $k->value : (int) $k;
        }
        $filter->setKinds($kindInts);

        foreach ($filters as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($filter, $method)) {
                if ($key === 'tag') {
                    $filter->setTag($value[0], $value[1]);
                } else {
                    $filter->$method($value);
                }
            }
        }

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $set = $relaySet ?? $defaultRelaySet;

        return $this->relayRequestFactory->createTimedRequest($set, $requestMessage);
    }

    /**
     * @param array<string, mixed> $response Return value of {@see Request::send()}: relay URL → message list|Throwable
     * @return list<mixed>
     */
    public function processResponse(array $response, callable $eventHandler): array
    {
        $results = [];
        foreach ($response as $relayUrl => $relayRes) {
            if ($relayRes instanceof \Throwable) {
                $this->logger->error(sprintf(
                    'Relay error at %s: %s',
                    self::relayLogLabel($relayUrl),
                    $relayRes->getMessage()
                ), [
                    'relay' => $relayUrl,
                    'error' => $relayRes->getMessage(),
                ]);
                continue;
            }

            $itemEstimate = \is_countable($relayRes) ? \count($relayRes) : null;
            $this->logger->debug(sprintf('Processing relay response from %s', self::relayLogLabel($relayUrl)), [
                'relay' => $relayUrl,
                'item_count' => $itemEstimate,
            ]);

            foreach ($relayRes as $item) {
                try {
                    if (!\is_object($item)) {
                        $this->logger->warning(sprintf(
                            'Invalid response item from %s',
                            self::relayLogLabel($relayUrl)
                        ), [
                            'relay' => $relayUrl,
                            'item' => $item,
                        ]);
                        continue;
                    }

                    switch ($item->type) {
                        case 'EVENT':
                            $this->logger->debug(sprintf('Processing event from %s', self::relayLogLabel($relayUrl)), [
                                'relay' => $relayUrl,
                                'event_id' => $item->event->id ?? 'unknown',
                            ]);
                            $result = $eventHandler($item->event);
                            if ($result !== null) {
                                $results[] = $result;
                            }
                            break;
                        case 'AUTH':
                            $this->logger->warning(sprintf(
                                'Relay %s requires authentication',
                                self::relayLogLabel($relayUrl)
                            ), [
                                'relay' => $relayUrl,
                                'response' => $item,
                            ]);
                            break;
                        case 'ERROR':
                        case 'NOTICE':
                            $msg = (string) ($item->message ?? 'No message');
                            $this->logger->warning(sprintf(
                                '[%s] %s: %s',
                                self::relayLogLabel($relayUrl),
                                $item->type,
                                $msg
                            ), [
                                'relay' => $relayUrl,
                                'type' => $item->type,
                                'message' => $msg,
                            ]);
                            break;
                    }
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Error processing event from relay %s: %s',
                        self::relayLogLabel($relayUrl),
                        $e->getMessage()
                    ), [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        return $results;
    }
}
