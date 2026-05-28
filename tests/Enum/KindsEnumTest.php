<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\KindsEnum;
use PHPUnit\Framework\TestCase;

final class KindsEnumTest extends TestCase
{
    public function testMagazineCategoryKindsExcludePublicationSections(): void
    {
        $magazine = KindsEnum::magazineCategoryKindValues();
        self::assertContains(KindsEnum::LONGFORM->value, $magazine);
        self::assertContains(KindsEnum::WIKI->value, $magazine);
        self::assertNotContains(KindsEnum::PUBLICATION_CONTENT->value, $magazine);
        self::assertNotContains(KindsEnum::WIKI_ARTICLE->value, $magazine);
    }

    public function testPublicationSectionKindsIncludeMarkdownAndAsciidoc(): void
    {
        $sections = KindsEnum::publicationSectionKindValues();
        self::assertContains(KindsEnum::LONGFORM->value, $sections);
        self::assertContains(KindsEnum::WIKI->value, $sections);
        self::assertContains(KindsEnum::PUBLICATION_CONTENT->value, $sections);
        self::assertContains(KindsEnum::WIKI_ARTICLE->value, $sections);
    }

    public function testSearchableArticleKindsExclude30041(): void
    {
        $search = KindsEnum::searchableArticleKindValues(true);
        self::assertContains(KindsEnum::WIKI_ARTICLE->value, $search);
        self::assertNotContains(KindsEnum::PUBLICATION_CONTENT->value, $search);
        self::assertNotContains(KindsEnum::PUBLICATION_INDEX->value, $search);
    }

    public function testMarkdownAndAsciidocBodyKindsAreDisjoint(): void
    {
        $md = KindsEnum::markdownBodyKindValues();
        $ad = KindsEnum::asciidocBodyKindValues();
        self::assertSame([], array_intersect($md, $ad));
        self::assertSame(
            KindsEnum::articleBodyKindValues(),
            array_values(array_merge($md, $ad)),
        );
    }
}
