<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrWireEvent;
use swentel\nostr\Message\RequestMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for Mercury index relays ({@code http://…} / {@code https://…}).
 * Maps REST responses into the wire shape expected by {@see NostrRelayQuery::processResponse()}.
 */
final readonly class MercuryHttpRelayClient
{
    private const DEFAULT_FILTER_LIMIT = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private int $relayRequestTimeoutSec = 12,
    ) {
    }

    /**
     * @param list<string> $httpRelayUrls
     *
     * @return array<string, mixed> Same top-level shape as {@see \swentel\nostr\Request\Request::send()}
     */
    public function queryRequestMessage(array $httpRelayUrls, RequestMessage $requestMessage, ?int $timeoutSec = null): array
    {
        $filters = $this->extractFiltersFromRequestMessage($requestMessage);
        $out = [];
        foreach ($httpRelayUrls as $relayUrl) {
            if ($relayUrl === '') {
                continue;
            }
            try {
                $out[$relayUrl] = $this->queryFilters($relayUrl, $filters, $timeoutSec);
            } catch (\Throwable $e) {
                $this->logger->warning('nostr.mercury.query_failed', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                    'exception_class' => \get_class($e),
                ]);
                $out[$relayUrl] = $e;
            }
        }

        return $out;
    }

    public function queryById(string $relayUrl, string $eventId, ?int $timeoutSec = null): ?object
    {
        if ($eventId === '' || !ctype_xdigit($eventId) || 64 !== \strlen($eventId)) {
            return null;
        }
        $url = $this->apiBase($relayUrl).'/api/events/'.rawurlencode($eventId);
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => (float) ($timeoutSec ?? $this->relayRequestTimeoutSec),
            'headers' => ['Accept' => 'application/json'],
        ]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Mercury GET event failed: HTTP '.$response->getStatusCode());
        }
        $body = $response->toArray(false);
        $eventData = $body['data'] ?? $body;
        if (!\is_array($eventData)) {
            return null;
        }

        return $this->wireEventFromArray($eventData);
    }

    /**
     * @return bool|\Throwable
     */
    public function publish(string $relayUrl, NostrWireEvent $event): bool|\Throwable
    {
        try {
            $payload = $this->eventToPublishPayload($event);
            $url = $this->apiBase($relayUrl).'/api/events';
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => (float) $this->relayRequestTimeoutSec,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => $payload,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            return new \RuntimeException('Mercury publish failed: HTTP '.$status);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $filters
     *
     * @return list<object>
     */
    private function queryFilters(string $relayUrl, array $filters, ?int $timeoutSec): array
    {
        $wireMessages = [];
        $seenIds = [];
        foreach ($filters as $filter) {
            $filter = $this->ensureLimit($filter);
            $events = $this->postFilter($relayUrl, $filter, $timeoutSec);
            foreach ($events as $event) {
                $id = (string) ($event->id ?? '');
                if ($id !== '' && isset($seenIds[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seenIds[$id] = true;
                }
                $msg = new \stdClass();
                $msg->type = 'EVENT';
                $msg->event = $event;
                $wireMessages[] = $msg;
            }
        }

        return $wireMessages;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<object>
     */
    private function postFilter(string $relayUrl, array $filter, ?int $timeoutSec): array
    {
        $url = $this->apiBase($relayUrl).'/api/events/filter';
        $response = $this->httpClient->request('POST', $url, [
            'timeout' => (float) ($timeoutSec ?? $this->relayRequestTimeoutSec),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'json' => $filter,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Mercury filter query failed: HTTP '.$response->getStatusCode());
        }
        $body = $response->toArray(false);
        $rows = $body['data'] ?? [];
        if (!\is_array($rows)) {
            return [];
        }
        $events = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $events[] = $this->wireEventFromArray($row);
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFiltersFromRequestMessage(RequestMessage $requestMessage): array
    {
        $decoded = json_decode($requestMessage->generate(), true);
        if (!\is_array($decoded) || \count($decoded) < 3) {
            return [['limit' => self::DEFAULT_FILTER_LIMIT]];
        }
        array_shift($decoded);
        array_shift($decoded);

        $filters = [];
        foreach ($decoded as $item) {
            if (\is_array($item)) {
                $filters[] = $item;
            }
        }

        return $filters !== [] ? $filters : [['limit' => self::DEFAULT_FILTER_LIMIT]];
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private function ensureLimit(array $filter): array
    {
        if (!isset($filter['limit']) || (int) $filter['limit'] <= 0) {
            $filter['limit'] = self::DEFAULT_FILTER_LIMIT;
        }

        return $filter;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function wireEventFromArray(array $row): object
    {
        $json = json_encode($row, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $verified = NostrWireEvent::fromVerified($json);
        if ($verified !== null) {
            return $this->wireEventObjectFromVerified($verified);
        }

        return json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
    }

    private function wireEventObjectFromVerified(NostrWireEvent $event): object
    {
        $o = new \stdClass();
        $o->id = $event->getId();
        $o->pubkey = $event->getPublicKey();
        $o->created_at = $event->getCreatedAt();
        $o->kind = $event->getKind();
        $o->tags = $event->getTags();
        $o->content = $event->getContent();
        $o->sig = $event->getSignature();

        return $o;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventToPublishPayload(NostrWireEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'pubkey' => $event->getPublicKey(),
            'created_at' => $event->getCreatedAt(),
            'kind' => $event->getKind(),
            'tags' => $event->getTags(),
            'content' => $event->getContent(),
            'sig' => $event->getSignature(),
        ];
    }

    private function apiBase(string $relayUrl): string
    {
        return rtrim(trim($relayUrl), '/');
    }
}
