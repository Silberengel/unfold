<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ProfileDisplayName;
use PHPUnit\Framework\TestCase;

final class ProfileDisplayNameTest extends TestCase
{
    private const NPUB = 'npub1l5sga6xg72phsz5422ykujprejwud075ggrr3z2hwyrfgr7eylqstegx9z';

    public function testPrefersDisplayNameOverName(): void
    {
        $meta = (object) ['display_name' => 'Alex', 'name' => 'alex@example.com'];

        $this->assertSame('Alex', ProfileDisplayName::resolve($meta, self::NPUB));
    }

    public function testFallsBackToNameWhenDisplayNameMissing(): void
    {
        $meta = (object) ['name' => 'Bob'];

        $this->assertSame('Bob', ProfileDisplayName::resolve($meta, self::NPUB));
    }

    public function testIgnoresPlaceholderName(): void
    {
        $placeholder = substr(self::NPUB, 0, 8).'…'.substr(self::NPUB, -4);
        $meta = (object) ['name' => $placeholder];

        $this->assertSame(
            ProfileDisplayName::shortNpubLabel(self::NPUB),
            ProfileDisplayName::resolve($meta, self::NPUB),
        );
    }
}
