<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class WeekendDistributionScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'even_weekends';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $counts = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            if (CarbonImmutable::parse($slot['starts_at'])->isWeekend()) {
                $counts[$gene['resource_id']] = ($counts[$gene['resource_id']] ?? 0) + 1;
            }
        }
        $weight = (int) config('planning.weights.even_weekends', 1);
        if ($counts === []) {
            return new ScoreComponent($this->code(), 'Równomierny rozkład weekendów', 0, $weight);
        }
        $avg = array_sum($counts) / count($counts);
        $score = (int) array_sum(array_map(fn (int $count): int => (int) (($count - $avg) ** 2 * 100), $counts)) * $weight;

        return new ScoreComponent($this->code(), 'Równomierny rozkład weekendów', $score, $weight);
    }
}
