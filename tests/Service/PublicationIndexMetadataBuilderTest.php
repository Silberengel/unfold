<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\NostrKeyHelper;
use App\Service\PublicationIndexMetadataBuilder;
use PHPUnit\Framework\TestCase;

final class PublicationIndexMetadataBuilderTest extends TestCase
{
    private const PUBKEY = '573634b648634cbad10f2451776089ea21090d9407f715e83c577b4611ae6edc';

    public function testBuildCollectsRepeatableTagsInOrder(): void
    {
        $index = new Event();
        $index->setPubkey(self::PUBKEY);
        $index->setCreatedAt(1769880727);
        $index->setTags([
            ['author', 'Aesop'],
            ['author', 'Editor Collective'],
            ['t', 'fables'],
            ['t', 'classical'],
            ['source', 'https://booksonline.org/'],
            ['i', 'isbn:9780765382030'],
            ['type', 'book'],
            ['version', '1.0'],
            ['published_on', '2003-05-13'],
            ['published_by', 'public domain'],
            ['summary', 'A collection of fables.'],
            ['auto-update', 'no'],
            ['p', '7c3bd3d882304e1fb21bbe1d3eb914a6152c747f315fbed6893e99f7119048ef'],
        ]);

        $meta = (new PublicationIndexMetadataBuilder(new NostrKeyHelper()))->build($index);

        self::assertSame(['Aesop', 'Editor Collective'], $meta->authors);
        self::assertSame(['fables', 'classical'], $meta->topics);
        self::assertSame(['https://booksonline.org/'], $meta->sources);
        self::assertSame(['isbn:9780765382030'], $meta->identifiers);
        self::assertSame('book', $meta->type);
        self::assertSame('1.0', $meta->version);
        self::assertSame(['2003-05-13'], $meta->publishedOn);
        self::assertSame(['public domain'], $meta->publishedBy);
        self::assertSame('A collection of fables.', $meta->summary);
        self::assertSame('no', $meta->autoUpdate);
        self::assertStringStartsWith('npub1', $meta->publisherNpub);
        self::assertSame(
            ['7c3bd3d882304e1fb21bbe1d3eb914a6152c747f315fbed6893e99f7119048ef'],
            $meta->nostrAuthorHexes,
        );
    }

    public function testBuildGettingOfWisdomExample(): void
    {
        $index = new Event();
        $index->setPubkey(self::PUBKEY);
        $index->setCreatedAt(1769880727);
        $index->setTags([
            ['d', 'pg3728-the-getting-of-wisdom'],
            ['title', 'THE GETTING OF WISDOM'],
            ['author', 'Unknown'],
            ['type', 'book'],
            ['version', '1.0'],
            ['auto-update', 'no'],
        ]);

        $meta = (new PublicationIndexMetadataBuilder(new NostrKeyHelper()))->build($index);

        self::assertSame(['Unknown'], $meta->authors);
        self::assertSame('book', $meta->type);
        self::assertSame('1.0', $meta->version);
        self::assertSame('no', $meta->autoUpdate);
        self::assertSame([], $meta->publishedBy);
        self::assertSame([], $meta->publishedOn);
    }
}
