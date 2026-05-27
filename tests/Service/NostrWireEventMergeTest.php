<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NostrKeyHelper;
use App\Service\NostrWireEventMerge;
use PHPUnit\Framework\TestCase;

final class NostrWireEventMergeTest extends TestCase
{
    private NostrWireEventMerge $m;

    protected function setUp(): void
    {
        $this->m = new NostrWireEventMerge(new NostrKeyHelper());
    }

    public function testAuthorIdentToHexLowerAcceptsHex(): void
    {
        $h = str_repeat('a', 64);
        $this->assertSame($h, $this->m->authorIdentToHexLower($h));
    }

    public function testWireEventSupersedesByCreatedAt(): void
    {
        $older = (object) ['kind' => 1, 'id' => str_repeat('1', 64), 'created_at' => 100, 'pubkey' => str_repeat('b', 64)];
        $newer = (object) ['kind' => 1, 'id' => str_repeat('2', 64), 'created_at' => 200, 'pubkey' => str_repeat('b', 64)];

        $this->assertTrue($this->m->wireEventSupersedes($newer, $older));
        $this->assertFalse($this->m->wireEventSupersedes($older, $newer));
    }

    public function testWireEventSupersedesTieBreakByIdWhenSameCreatedAt(): void
    {
        $t = 50;
        $a = (object) ['kind' => 1, 'id' => str_repeat('a', 64), 'created_at' => $t, 'pubkey' => str_repeat('b', 64)];
        $b = (object) ['kind' => 1, 'id' => str_repeat('b', 64), 'created_at' => $t, 'pubkey' => str_repeat('b', 64)];

        $this->assertTrue($this->m->wireEventSupersedes($a, $b), 'a < b lexicographically so a supersedes when created_at equal');
        $this->assertFalse($this->m->wireEventSupersedes($b, $a));
    }

    public function testMergeNip33ParameterizedWireEventsKeepsNewerByAddress(): void
    {
        $k = 30_040;
        $pk = str_repeat('c', 64);
        $d = 'my-article';
        $tags = [['d', $d]];

        $old = (object) [
            'kind' => $k,
            'id' => str_repeat('1', 64),
            'pubkey' => $pk,
            'created_at' => 10,
            'tags' => $tags,
        ];
        $new = (object) [
            'kind' => $k,
            'id' => str_repeat('2', 64),
            'pubkey' => $pk,
            'created_at' => 20,
            'tags' => $tags,
        ];

        $merged = $this->m->mergeNip33ParameterizedWireEvents([$old, $new]);
        $this->assertCount(1, $merged);
        $this->assertSame(20, (int) $merged[0]->created_at);
    }

    public function testMergeKind0ByReplaceableAddress(): void
    {
        $pk = str_repeat('d', 64);
        $a = (object) ['kind' => 0, 'id' => str_repeat('1', 64), 'pubkey' => $pk, 'created_at' => 1];
        $b = (object) ['kind' => 0, 'id' => str_repeat('2', 64), 'pubkey' => $pk, 'created_at' => 2];

        $out = $this->m->mergeKind0EventsByReplaceableAddress([$a, $b]);
        $this->assertCount(1, $out);
        $this->assertSame(2, (int) array_values($out)[0]->created_at);
    }

    public function testIsReplaceableByKindAndPubkeyNip(): void
    {
        $this->assertTrue($this->m->isReplaceableByKindAndPubkeyNip(0));
        $this->assertTrue($this->m->isReplaceableByKindAndPubkeyNip(10_000));
        $this->assertFalse($this->m->isReplaceableByKindAndPubkeyNip(1));
    }

    public function testIsNip33ParameterizedKindRange(): void
    {
        $this->assertTrue($this->m->isNip33ParameterizedKind(30_000));
        $this->assertTrue($this->m->isNip33ParameterizedKind(39_999));
        $this->assertFalse($this->m->isNip33ParameterizedKind(29_999));
        $this->assertFalse($this->m->isNip33ParameterizedKind(40_000));
    }

    public function testMagazineEventToPublicationEntityAcceptsDecodedWireArray(): void
    {
        $pk = str_repeat('e', 64);
        $id = str_repeat('f', 64);
        $data = [
            'kind' => 30_040,
            'id' => $id,
            'pubkey' => $pk,
            'content' => 'summary',
            'created_at' => 1_700_000_000,
            'tags' => [['d', 'newsroom-magazine-on-imwald-by-laeserin-category-bitcoin']],
            'sig' => str_repeat('a', 128),
        ];

        $entity = $this->m->magazineEventToPublicationEntity($data);
        $this->assertNotNull($entity);
        $this->assertSame($id, $entity->getId());
        $this->assertSame(30_040, $entity->getKind());
        $this->assertSame($pk, $entity->getPubkey());
        $this->assertSame('summary', $entity->getContent());
        $this->assertSame(1_700_000_000, $entity->getCreatedAt());
        $this->assertSame($data['tags'], $entity->getTags());
    }
}
