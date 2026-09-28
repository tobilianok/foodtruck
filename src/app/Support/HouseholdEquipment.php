<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\Household;

class HouseholdEquipment
{
    /**
     * Enregistre les appareils du foyer : cases cochées + appareils saisis librement
     * (séparés par des virgules), ajoutés à la liste commune s'ils n'existent pas.
     */
    public static function sync(Household $household, array $equipmentIds, ?string $others, ?int $userId): void
    {
        $ids = collect($equipmentIds)->map(fn ($id) => (int) $id)->filter()->unique();

        foreach (explode(',', (string) $others) as $name) {
            if (trim($name) === '') {
                continue;
            }

            $equipment = Equipment::findOrCreateByName($name, $userId);
            if ($equipment) {
                $ids->push($equipment->id);
            }
        }

        $household->equipment()->sync($ids->unique()->values()->all());
    }
}
