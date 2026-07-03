<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ResourceAvailabilityCalendar;
use App\Planning\Engine\Support\ScheduleFacts;

final class AvailabilityConflictConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'availability_conflict';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            if (ResourceAvailabilityCalendar::blocksResource($problem, $gene['resource_id'], $slot)) {
                $violations[] = $this->violation('Przypisanie koliduje z kalendarzem dostępności zasobu.', $gene['resource_id'], $gene['slot_id']);
            }
        }

        return $violations;
    }
}
