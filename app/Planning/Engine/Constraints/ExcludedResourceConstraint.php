<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class ExcludedResourceConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'excluded_resource';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            if ($problem->unitRuleForSlot($slot, $gene['resource_id'])['usage_mode'] === 'excluded') {
                $violations[] = $this->violation('Zasób jest wykluczony z jednostki planistycznej.', $gene['resource_id'], $gene['slot_id']);
            }
        }

        return $violations;
    }
}
