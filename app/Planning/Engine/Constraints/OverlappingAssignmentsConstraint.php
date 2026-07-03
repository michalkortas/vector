<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class OverlappingAssignmentsConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'overlap';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::assignmentsByResource($problem, $chromosome) as $resourceId => $assignments) {
            for ($i = 0; $i < count($assignments); $i++) {
                for ($j = $i + 1; $j < count($assignments); $j++) {
                    if (ScheduleFacts::overlaps($assignments[$i]['starts_at'], $assignments[$i]['ends_at'], $assignments[$j]['starts_at'], $assignments[$j]['ends_at'])) {
                        $violations[] = $this->violation('Zasób ma nakładające się przypisania.', $resourceId, $assignments[$j]['slot_id']);
                    }
                }
            }
        }

        return $violations;
    }
}
