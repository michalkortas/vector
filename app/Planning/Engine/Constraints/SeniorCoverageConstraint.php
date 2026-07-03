<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class SeniorCoverageConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'senior_coverage_missing';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $groups = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            $slot = $problem->slot($gene['slot_id']);
            if ($slot === null) {
                continue;
            }

            $metadata = $slot['metadata'] ?? [];
            $groupKey = $metadata['senior_coverage_group'] ?? null;
            if ($groupKey === null) {
                continue;
            }

            $groups[$groupKey] ??= [
                'required' => (int) ($metadata['senior_required_count'] ?? 1),
                'senior_skill_ids' => array_map('intval', $metadata['senior_skill_ids'] ?? []),
                'senior_count' => 0,
                'slot_id' => $gene['slot_id'],
            ];

            if ($gene['resource_id'] === null) {
                continue;
            }

            $skills = $problem->skillsByResource[$gene['resource_id']] ?? [];
            if (array_intersect($groups[$groupKey]['senior_skill_ids'], $skills) !== []) {
                $groups[$groupKey]['senior_count']++;
            }
        }

        $violations = [];
        foreach ($groups as $groupKey => $group) {
            if ($group['senior_count'] < $group['required']) {
                $violations[] = $this->violation(
                    'W grupie stanowisk brakuje osoby starszej.',
                    null,
                    $group['slot_id'],
                    ['group_key' => $groupKey, 'required' => $group['required'], 'actual' => $group['senior_count']],
                );
            }
        }

        return $violations;
    }
}
