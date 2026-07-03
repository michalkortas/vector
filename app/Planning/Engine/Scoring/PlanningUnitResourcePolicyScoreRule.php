<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class PlanningUnitResourcePolicyScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'planning_unit_resource_policy';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $score = 0;
        $fallback = 0;
        $secondary = 0;
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $rule = $problem->unitRuleForSlot($slot, $gene['resource_id']);
            $score += (int) $rule['penalty'];
            $fallback += $rule['usage_mode'] === 'fallback' ? 1 : 0;
            $secondary += $rule['usage_mode'] === 'secondary' ? 1 : 0;
        }

        return new ScoreComponent($this->code(), 'Polityka obsady jednostek', $score, null, false, ['fallback' => $fallback, 'secondary' => $secondary]);
    }
}
