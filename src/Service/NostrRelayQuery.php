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
        private RelayFetchedEventPersister $relayFetchedEventPersister,
        private NostrRelayTransport $relayTransport,
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
        ?int $relayTimeoutSec = null,
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

        return $this->relayRequestFactory->createTimedRequest($set, $requestMessage, $relayTimeoutSec);
    }

    /**
     * @param list<string> $relayUrls Mixed wss + http tenant/author relay URLs
     *
     * @return array<string, mixed> Same shape as {@see Request::send()}
     */
    public function sendToUrls(array $relayUrls, RequestMessage $requestMessage, ?int $relayTimeoutSec = null): array
    {
        return $this->relayTransport->sendToUrls($relayUrls, $requestMessage, $relayTimeoutSec);
    }

    /**
     * @param list<int|\BackedEnum> $kinds
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function sendNostrQuery(
        array $relayUrls,
        array $kinds = [],
        array $filters = [],
        ?int $relayTimeoutSec = null,
    ): array {
        $requestMessage = $this->buildRequestMessage($kinds, $filters);

        return $this->sendToUrls($relayUrls, $requestMessage, $relayTimeoutSec);
    }

    /**
     * @param list<int|\BackedEnum> $kinds
     * @param array<string, mixed> $filters
     */
    public function sendCreatedRequest(Request $request, array $relayUrls): array
    {
        $requestMessage = $this->requestMessageFromRequest($request);
        $timeout = $this->timeoutFromRequest($request);

        return $this->sendToUrls($relayUrls, $requestMessage, $timeout);
    }

    /**
     * @param list<int|\BackedEnum> $kinds
     * @param array<string, mixed> $filters
     */
    private function buildRequestMessage(array $kinds, array $filters): RequestMessage
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $kindInts = [];
        foreach ($kinds as $k) {
            $kindInts[] = $k instanceof \BackedEnum ? (int) $k->value : (int) $k;
        }
        if ($kindInts !== []) {
            $filter->setKinds($kindInts);
        }

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

        return new RequestMessage($subscriptionId, [$filter]);
    }

    private function requestMessageFromRequest(Request $request): RequestMessage
    {
        $ref = new \ReflectionClass($request);
        $prop = $ref->getProperty('payload');
        $prop->setAccessible(true);
        $payload = $prop->getValue($request);
        if (!\is_string($payload)) {
            throw new \RuntimeException('Nostr request payload missing');
        }
        $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || \count($decoded) < 2) {
            throw new \RuntimeException('Invalid Nostr request payload');
        }
        array_shift($decoded);
        $subscriptionId = (string) array_shift($decoded);
        $filters = [];
        foreach ($decoded as $filterArray) {
            if (!\is_array($filterArray)) {
                continue;
            }
            $filters[] = $this->filterFromArray($filterArray);
        }
        if ($filters === []) {
            $filters[] = new Filter();
        }

        return new RequestMessage($subscriptionId, $filters);
    }

    /**
     * @param array<string, mixed> $filterArray
     */
    private function filterFromArray(array $filterArray): Filter
    {
        $filter = new Filter();
        if (isset($filterArray['ids']) && \is_array($filterArray['ids'])) {
            $filter->setIds($filterArray['ids']);
        }
        if (isset($filterArray['authors']) && \is_array($filterArray['authors'])) {
            $filter->setAuthors($filterArray['authors']);
        }
        if (isset($filterArray['kinds']) && \is_array($filterArray['kinds'])) {
            $filter->setKinds(array_map('intval', $filterArray['kinds']));
        }
        if (isset($filterArray['since'])) {
            $filter->setSince((int) $filterArray['since']);
        }
        if (isset($filterArray['until'])) {
            $filter->setUntil((int) $filterArray['until']);
        }
        if (isset($filterArray['limit'])) {
            $filter->setLimit((int) $filterArray['limit']);
        }
        foreach ($filterArray as $key => $value) {
            if (!\is_string($key) || !str_starts_with($key, '#') || !\is_array($value)) {
                continue;
            }
            $filter->setTag($key, $value);
        }

        return $filter;
    }

    private function timeoutFromRequest(Request $request): ?int
    {
        $ref = new \ReflectionClass($request);
        if (!$ref->hasProperty('timeout')) {
            return null;
        }
        $prop = $ref->getProperty('timeout');
        $prop->setAccessible(true);
        $timeout = $prop->getValue($request);

        return \is_int($timeout) || \is_float($timeout) ? (int) $timeout : null;
    }

    /**
     * @param array<string, mixed> $response Return value of {@see Request::send()}: relay URL → message list|Throwable
     * @return list<mixed>
     */
    public function processResponse(array $response, callable $eventHandler): array
    {
        $this->relayFetchedEventPersister->beginResponse();
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
                        // Non-object relay responses (connection drops, bad HTTP status, etc.)
                        // are expected for dead or misconfigured relays; INFO keeps them out of
                        // the warning stream without losing them from the log file.
                        $this->logger->info(sprintf(
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
                            $this->relayFetchedEventPersister->persistFromTenantRelay($item->event, $relayUrl);
                            $result = $eventHandler($item->event);
                            if ($result !== null) {
                                $results[] = $result;
                            }
                            break;
                        case 'AUTH':
                            // AUTH challenges are expected from paid/restricted relays; we do not
                            // support NIP-42 signing, so this is a no-op but not an error.
                            $this->logger->info(sprintf(
                                'Relay %s requires authentication',
                                self::relayLogLabel($relayUrl)
                            ), [
                                'relay' => $relayUrl,
                                'response' => $item,
                            ]);
                            break;
                        case 'ERROR':
                        case 'NOTICE':
                            // Relay-level ERROR/NOTICE messages (rate limits, dropped connections,
                            // etc.) are external signals; INFO keeps them observable without
                            // polluting the warning tier.
                            $msg = (string) ($item->message ?? 'No message');
                            $this->logger->info(sprintf(
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
