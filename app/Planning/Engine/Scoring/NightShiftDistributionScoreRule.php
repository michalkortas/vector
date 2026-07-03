<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class NightShiftDistributionScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'even_nights';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $counts = [];
        $totals = [];
        foreach ($problem->resources as $resourceId => $resource) {
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                continue;
            }
            $counts[$resourceId] = 0;
            $totals[$resourceId] = 0;
        }

        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $resource = $problem->resources[$gene['resource_id']] ?? [];
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                continue;
            }

            $counts[$gene['resource_id']] = $counts[$gene['resource_id']] ?? 0;
            $totals[$gene['resource_id']] = ($totals[$gene['resource_id']] ?? 0) + 1;
            if (($slot['shift_code'] ?? '') === 'NIGHT_12H') {
                $counts[$gene['resource_id']] = ($counts[$gene['resource_id']] ?? 0) + 1;
            }
        }

        $weight = (int) config('planning.weights.even_nights', 1);
        $variancePenalty = $this->variancePenalty($counts);
        $nightSharePenalty = $this->nightSharePenalty($counts, $totals);

        return new ScoreComponent($this->code(), 'Bilans dniówek i nocek', ($variancePenalty + $nightSharePenalty) * $weight, $weight, false, [
            'night_counts' => $counts,
            'assignment_counts' => $totals,
            'variance_penalty' => $variancePenalty,
            'night_share_penalty' => $nightSharePenalty,
        ]);
    }

    private function variancePenalty(array $counts): int
    {
        if ($counts === []) {
            return 0;
        }
        $avg = array_sum($counts) / count($counts);

        return (int) array_sum(array_map(fn (int $count): int => (int) (($count - $avg) ** 2 * 100), $counts));
    }

    private function nightSharePenalty(array $counts, array $totals): int
    {
        $penalty = 0;
        foreach ($totals as $resourceId => $total) {
            if ($total < 3) {
                continue;
            }

            $nightPercent = (int) round((($counts[$resourceId] ?? 0) / $total) * 100);
            $overPreferred = max(0, $nightPercent - 60);
            $penalty += ($overPreferred ** 2) * 25;
        }

        return $penalty;
    }
}
