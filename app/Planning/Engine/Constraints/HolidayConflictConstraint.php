<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\HolidayCalendar;
use App\Planning\Engine\Support\ScheduleFacts;

final class HolidayConflictConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'holiday_conflict';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            if (HolidayCalendar::blocksResource($problem, $gene['resource_id'], $slot)) {
                $violations[] = $this->violation('Przypisanie wypada w dzień świąteczny.', $gene['resource_id'], $gene['slot_id']);
            }
        }

        return $violations;
    }
}
