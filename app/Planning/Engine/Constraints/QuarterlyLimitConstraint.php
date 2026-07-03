<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class QuarterlyLimitConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'quarterly_limit_exceeded';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $minutes = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $minutes[$gene['resource_id']] = ($minutes[$gene['resource_id']] ?? 0) + (int) $slot['duration_minutes'];
        }

        $violations = [];
        foreach ($minutes as $resourceId => $value) {
            $limit = (int) ($problem->limitsByResource[$resourceId]['max_minutes_per_quarter'] ?? 0);
            if ($limit > 0 && $value > $limit) {
                $violations[] = $this->violation('Przekroczono kwartalny limit minut.', $resourceId, null, ['minutes' => $value, 'limit' => $limit]);
            }
        }

        return $violations;
    }
}
