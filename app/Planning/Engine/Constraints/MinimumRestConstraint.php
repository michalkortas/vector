<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class MinimumRestConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'min_rest_violation';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $violations = [];
        foreach (ScheduleFacts::assignmentsByResource($problem, $chromosome) as $resourceId => $assignments) {
            $minRest = (int) ($problem->limitsByResource[$resourceId]['min_rest_minutes'] ?? 0);
            if ($minRest <= 0) {
                continue;
            }
            for ($i = 1; $i < count($assignments); $i++) {
                $rest = (int) CarbonImmutable::parse($assignments[$i - 1]['ends_at'])->diffInMinutes(CarbonImmutable::parse($assignments[$i]['starts_at']), false);
                if ($rest >= 0 && $rest < $minRest) {
                    $violations[] = $this->violation('Minimalny odpoczynek między zmianami jest zbyt krótki.', $resourceId, $assignments[$i]['slot_id'], ['rest_minutes' => $rest, 'required_minutes' => $minRest]);
                }
            }
        }

        return $violations;
    }
}
