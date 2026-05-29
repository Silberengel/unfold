<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MercuryHttpRelayClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MercuryHttpRelayClientTest extends TestCase
{
    public function testQueryRequestMessageMapsEventsToWireShape(): void
    {
        $eventJson = json_encode([
            'id' => '05b26ee484c7d38dfb9865b572a2738a10701e3415d3fa210b4682b9b83d7682',
            'kind' => 0,
            'pubkey' => '208f33c93e1133ecbb444ac03adf30d4222ff77d22067eec5673e9f74c0e90a9',
            'content' => '{}',
            'tags' => [],
            'created_at' => 1779986469,
            'sig' => '97e03b0ed7c15630a01e973ccdbcf1a6b62f01d84d6aa7861b6ae8488cc351462fa974908236eadaed39b9b63f162bd6ec1a5eaa2ce786f860fd5a408a9460af',
        ], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => [json_decode($eventJson, true)]], \JSON_THROW_ON_ERROR)),
        ]);
        $mercury = new MercuryHttpRelayClient($client, new NullLogger(), 5);
        $filter = new Filter();
        $filter->setKinds([0]);
        $filter->setLimit(1);
        $requestMessage = new RequestMessage('sub', [$filter]);

        $response = $mercury->queryRequestMessage(['http://mercury.test'], $requestMessage);

        $this->assertArrayHasKey('http://mercury.test', $response);
        $this->assertIsArray($response['http://mercury.test']);
        $this->assertCount(1, $response['http://mercury.test']);
        $item = $response['http://mercury.test'][0];
        $this->assertSame('EVENT', $item->type);
        $this->assertSame(
            '05b26ee484c7d38dfb9865b572a2738a10701e3415d3fa210b4682b9b83d7682',
            $item->event->id,
        );
    }

    public function testPublishReturnsTrueOnSuccess(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"ok":true}', ['http_code' => 201]),
        ]);
        $mercury = new MercuryHttpRelayClient($client, new NullLogger(), 5);
        $wire = new \swentel\nostr\Event\Event();
        $wire->setId('05b26ee484c7d38dfb9865b572a2738a10701e3415d3fa210b4682b9b83d7682')
            ->setPublicKey('208f33c93e1133ecbb444ac03adf30d4222ff77d22067eec5673e9f74c0e90a9')
            ->setCreatedAt(1779986469)
            ->setKind(1)
            ->setContent('hello')
            ->setSignature('97e03b0ed7c15630a01e973ccdbcf1a6b62f01d84d6aa7861b6ae8488cc351462fa974908236eadaed39b9b63f162bd6ec1a5eaa2ce786f860fd5a408a9460af');

        $result = $mercury->publish('http://mercury.test', $wire);

        $this->assertTrue($result);
    }
}
