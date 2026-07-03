<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class AbsenceConflictConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'absence_conflict';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            foreach ($problem->absences[$gene['resource_id']] ?? [] as $absence) {
                if ($absence['blocks_planning'] && ScheduleFacts::overlaps($slot['starts_at'], $slot['ends_at'], $absence['starts_at'], $absence['ends_at'])) {
                    $violations[] = $this->violation('Przypisanie koliduje z absencją.', $gene['resource_id'], $gene['slot_id']);
                }
            }
        }

        return $violations;
    }
}
