<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;

final class LockedAssignmentConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'locked_assignment_changed';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach ($problem->lockedAssignments as $key => $resourceId) {
            if (($chromosome->genes[$key] ?? null) !== $resourceId) {
                [$slotId] = array_map('intval', explode(':', $key));
                $violations[] = $this->violation('Zablokowane przypisanie zostało zmienione.', $resourceId, $slotId);
            }
        }

        return $violations;
    }
}
