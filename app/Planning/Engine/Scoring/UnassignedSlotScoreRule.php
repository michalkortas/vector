<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;

final class UnassignedSlotScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'unassigned_slot';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $count = count(array_filter($chromosome->genes, fn (?int $resourceId): bool => $resourceId === null));
        $weight = (int) config('planning.weights.unassigned_slot', 100000);

        return new ScoreComponent($this->code(), 'Nieobsadzone sloty', $count * $weight, $weight, true, ['count' => $count]);
    }
}
