<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class EvenHoursDistributionScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'even_hours';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $minutes = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $minutes[$gene['resource_id']] = ($minutes[$gene['resource_id']] ?? 0) + (int) $slot['duration_minutes'];
        }

        $paidAbsenceMinutes = ScheduleFacts::paidAbsenceMinutesByResource($problem);

        $score = 0;
        $underfilled = 0;
        $overfilled = 0;
        foreach ($problem->resources as $resourceId => $resource) {
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                continue;
            }

            $target = (int) ($problem->limitsByResource[$resourceId]['target_minutes_per_month'] ?? $problem->monthlyNormMinutes);
            $planned = ($minutes[$resourceId] ?? 0) + ($paidAbsenceMinutes[$resourceId] ?? 0);
            if ($planned < $target) {
                $underfilled += $target - $planned;
            } elseif ($planned > $target) {
                $overfilled += $planned - $target;
            }
        }

        $weight = (int) config('planning.weights.even_hours', 8000);
        $score += (int) floor($underfilled / 60) * $weight;
        $score += (int) floor($overfilled / 60) * $weight;

        return new ScoreComponent($this->code(), 'Dążenie do nominału', $score, $weight, false, ['underfilled_minutes' => $underfilled, 'overfilled_minutes' => $overfilled]);
    }
}
