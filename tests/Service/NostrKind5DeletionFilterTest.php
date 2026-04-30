<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\KindsEnum;
use App\Service\NostrKind5DeletionFilter;
use PHPUnit\Framework\TestCase;

final class NostrKind5DeletionFilterTest extends TestCase
{
    public function testKindTagMatchingStoredLongformIsRelevant(): void
    {
        $f = new NostrKind5DeletionFilter();
        $ev = (object) [
            'kind' => 5,
            'tags' => [['k', (string) KindsEnum::LONGFORM->value]],
        ];
        $this->assertTrue($f->isRelevantToStoredDbData($ev));
    }

    public function testKindTagForTextNoteIsNotRelevant(): void
    {
        $f = new NostrKind5DeletionFilter();
        $ev = (object) [
            'kind' => 5,
            'tags' => [['k', (string) KindsEnum::TEXT_NOTE->value]],
        ];
        $this->assertFalse($f->isRelevantToStoredDbData($ev));
    }

    public function testAddressTagWithStoredKindIsRelevant(): void
    {
        $f = new NostrKind5DeletionFilter();
        $pk = str_repeat('a', 64);
        $ev = (object) [
            'kind' => 5,
            'tags' => [['a', KindsEnum::LONGFORM->value.':'.$pk.':slug']],
        ];
        $this->assertTrue($f->isRelevantToStoredDbData($ev));
    }

    public function testAddressTagWithCurationSetKindIsRelevant(): void
    {
        $f = new NostrKind5DeletionFilter();
        $pk = str_repeat('c', 64);
        $ev = (object) [
            'kind' => 5,
            'tags' => [['a', KindsEnum::CURATION_SET->value.':'.$pk.':home']],
        ];
        $this->assertTrue($f->isRelevantToStoredDbData($ev));
    }
}
