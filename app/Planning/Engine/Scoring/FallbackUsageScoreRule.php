<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class FallbackUsageScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'fallback_usage';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $count = 0;
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $count += $problem->unitRuleForSlot($slot, $gene['resource_id'])['usage_mode'] === 'fallback' ? 1 : 0;
        }
        $weight = (int) config('planning.weights.fallback_usage', 1500);

        return new ScoreComponent($this->code(), 'Obsada rezerwowa', $count * $weight, $weight, false, ['count' => $count]);
    }
}
