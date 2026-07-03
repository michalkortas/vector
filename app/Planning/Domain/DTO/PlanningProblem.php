<?php

namespace App\Planning\Domain\DTO;

use Carbon\CarbonImmutable;

final class PlanningProblem
{
    public function __construct(
        public readonly int $periodId,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
        public readonly int $monthlyNormMinutes,
        public readonly int $quarterlyNormMinutes,
        public readonly array $resources,
        public readonly array $skillsByResource,
        public readonly array $planningUnits,
        public readonly array $demandSlots,
        public readonly array $requiredSkillsBySlot,
        public readonly array $absences,
        public readonly array $availabilityRules,
        public readonly array $holidays,
        public readonly array $limitsByResource,
        public readonly array $unitRules,
        public readonly array $substitutionPolicies = [],
        public readonly array $lockedAssignments = [],
    ) {
    }

    public function slotPositions(): array
    {
        $positions = [];
        foreach ($this->demandSlots as $slot) {
            for ($position = 1; $position <= (int) $slot['required_resources_count']; $position++) {
                $positions[] = ['slot_id' => (int) $slot['id'], 'position' => $position];
            }
        }

        return $positions;
    }

    public function slot(int $slotId): ?array
    {
        return $this->demandSlots[$slotId] ?? null;
    }

    public function geneKey(int $slotId, int $position): string
    {
        return $slotId.':'.$position;
    }

    public function unitRuleForSlot(array $slot, int $resourceId): array
    {
        $unitId = (int) $slot['planning_unit_id'];
        $shiftId = (int) ($slot['shift_template_id'] ?? 0);

        return $this->unitRules[$unitId][$shiftId][$resourceId]
            ?? $this->unitRules[$unitId][0][$resourceId]
            ?? ['usage_mode' => 'primary', 'penalty' => 0, 'priority' => 100];
    }
}
