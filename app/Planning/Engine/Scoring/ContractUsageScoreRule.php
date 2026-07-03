<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;

final class ContractUsageScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'contract_usage';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $minutes = 0;
        $resources = [];
        $preferredMax = [];
        $overPreferredMinutes = 0;
        $missingMinimumResources = [];

        foreach ($problem->resources as $resourceId => $resource) {
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage') {
                continue;
            }

            $resources[$resourceId] = 0;
            $preferredMax[$resourceId] = (int) ($resource['metadata']['preferred_max_minutes'] ?? 0);
        }

        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            $resource = $problem->resources[$gene['resource_id']] ?? [];
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage') {
                continue;
            }

            $slotMinutes = (int) $slot['duration_minutes'];
            $minutes += $slotMinutes;
            $resources[$gene['resource_id']] = ($resources[$gene['resource_id']] ?? 0) + $slotMinutes;
        }

        $weight = (int) config('planning.weights.contract_usage_per_hour', 2000);
        foreach ($resources as $resourceId => $resourceMinutes) {
            $max = $preferredMax[$resourceId] ?? 0;
            if ($max > 0 && $resourceMinutes > $max) {
                $overPreferredMinutes += $resourceMinutes - $max;
            }
            if ($resourceMinutes === 0) {
                $missingMinimumResources[] = $resourceId;
            }
        }
        $distributionPenaltyHours = $this->distributionPenaltyHours($resources);

        $score = ((int) floor($minutes / 60) * $weight)
            + ((int) floor($overPreferredMinutes / 60) * $weight * 8)
            + (count($missingMinimumResources) * $weight * 72)
            + ($distributionPenaltyHours * $weight);

        return new ScoreComponent($this->code(), 'Użycie kontraktów/zleceń', $score, $weight, false, [
            'minutes' => $minutes,
            'resources' => $resources,
            'preferred_max_minutes' => $preferredMax,
            'over_preferred_minutes' => $overPreferredMinutes,
            'missing_minimum_resource_ids' => $missingMinimumResources,
            'distribution_penalty_hours' => $distributionPenaltyHours,
        ]);
    }

    private function distributionPenaltyHours(array $resources): int
    {
        if (count($resources) <= 1) {
            return 0;
        }

        $average = array_sum($resources) / count($resources);
        $distanceMinutes = array_sum(array_map(fn (int $minutes): int => (int) abs($minutes - $average), $resources));

        return (int) floor($distanceMinutes / 60);
    }
}
