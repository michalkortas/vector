<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class MissingSkillConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'missing_skill';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null) {
                continue;
            }
            $missing = array_diff($problem->requiredSkillsBySlot[$gene['slot_id']] ?? [], $problem->skillsByResource[$gene['resource_id']] ?? []);
            if ($missing !== []) {
                $violations[] = $this->violation('Zasób nie ma wymaganych umiejętności.', $gene['resource_id'], $gene['slot_id'], ['missing_skills' => array_values($missing)]);
            }
        }

        return $violations;
    }
}
