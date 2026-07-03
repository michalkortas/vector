<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class NominalLimitConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'nominal_limit_exceeded';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $workMinutes = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $workMinutes[$gene['resource_id']] = ($workMinutes[$gene['resource_id']] ?? 0) + (int) $slot['duration_minutes'];
        }

        $paidAbsenceMinutes = ScheduleFacts::paidAbsenceMinutesByResource($problem);
        $violations = [];
        foreach ($problem->resources as $resourceId => $resource) {
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                continue;
            }

            $target = (int) ($problem->limitsByResource[$resourceId]['target_minutes_per_month'] ?? 0);
            if ($target === 0) {
                continue;
            }

            $total = ($workMinutes[$resourceId] ?? 0) + ($paidAbsenceMinutes[$resourceId] ?? 0);
            if ($total > $target) {
                $violations[] = $this->violation('Praca i urlopy przekraczają nominał etatowy.', $resourceId, null, ['minutes' => $total, 'target' => $target]);
            }
        }

        return $violations;
    }
}
