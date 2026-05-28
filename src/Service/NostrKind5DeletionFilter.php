<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;

/**
 * NIP-09 kind-5: keep deletion events that may affect MySQL-backed rows (profile, relay list, payto,
 * long-form, magazine 30040, legacy kind 30004). Skips thread/reply deletions to reduce relay payload.
 */
final class NostrKind5DeletionFilter
{
    public function isRelevantToStoredDbData(object $ev): bool
    {
        $kinds = $this->storedKindValues();
        foreach ($ev->tags ?? [] as $tag) {
            if (!\is_array($tag) && !\is_object($tag)) {
                continue;
            }
            $r = \is_object($tag) ? array_values((array) $tag) : $tag;
            if (!isset($r[0], $r[1])) {
                continue;
            }
            if ((string) $r[0] === 'k' && \in_array((int) $r[1], $kinds, true)) {
                return true;
            }
            if ((string) $r[0] === 'a') {
                $parts = explode(':', (string) $r[1], 3);
                $kindNum = (int) $parts[0];
                if (\in_array($kindNum, $kinds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function storedKindValues(): array
    {
        return array_merge(KindsEnum::articleBodyKindValues(), [
            KindsEnum::METADATA->value,
            KindsEnum::RELAY_LIST->value,
            KindsEnum::PAYMENT_TARGETS->value,
            KindsEnum::PUBLICATION_INDEX->value,
            KindsEnum::CURATION_SET->value,
        ]);
    }
}
