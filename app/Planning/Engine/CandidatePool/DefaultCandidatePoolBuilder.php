<?php

namespace App\Planning\Engine\CandidatePool;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Engine\Contracts\CandidatePoolBuilderInterface;
use App\Planning\Engine\Support\HolidayCalendar;
use App\Planning\Engine\Support\ResourceAvailabilityCalendar;
use App\Planning\Engine\Support\ScheduleFacts;

final class DefaultCandidatePoolBuilder implements CandidatePoolBuilderInterface
{
    public function build(PlanningProblem $problem): CandidatePool
    {
        $pools = [];
        foreach ($problem->slotPositions() as $position) {
            $slot = $problem->slot($position['slot_id']);
            $requiredSkills = $problem->requiredSkillsBySlot[$position['slot_id']] ?? [];
            $key = $problem->geneKey($position['slot_id'], $position['position']);
            $pools[$key] = [];

            foreach ($problem->resources as $resourceId => $resource) {
                if (! $resource['is_active']) {
                    continue;
                }
                if (array_diff($requiredSkills, $problem->skillsByResource[$resourceId] ?? []) !== []) {
                    continue;
                }
                if (HolidayCalendar::blocksResource($problem, $resourceId, $slot)) {
                    continue;
                }
                if (ResourceAvailabilityCalendar::blocksResource($problem, $resourceId, $slot)) {
                    continue;
                }

                $rule = $problem->unitRuleForSlot($slot, $resourceId);
                if ($rule['usage_mode'] === 'excluded') {
                    continue;
                }

                $blocked = false;
                foreach ($problem->absences[$resourceId] ?? [] as $absence) {
                    if ($absence['blocks_planning'] && ScheduleFacts::overlaps($slot['starts_at'], $slot['ends_at'], $absence['starts_at'], $absence['ends_at'])) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }

                $pools[$key][] = [
                    'resource_id' => $resourceId,
                    'usage_mode' => $rule['usage_mode'],
                    'penalty' => (int) $rule['penalty'],
                    'priority' => (int) $rule['priority'],
                ];
            }

            usort($pools[$key], fn (array $a, array $b): int => [$a['penalty'], $a['priority'], $a['resource_id']] <=> [$b['penalty'], $b['priority'], $b['resource_id']]);
        }

        return new CandidatePool($pools);
    }
}
