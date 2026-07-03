<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            $counts[$gene['resource_id']] = $counts[$gene['resource_id']] ?? 0;
            $totals[$gene['resource_id']] = ($totals[$gene['resource_id']] ?? 0) + 1;
            if (($slot['shift_code'] ?? '') === 'NIGHT_12H') {
                $counts[$gene['resource_id']] = ($counts[$gene['resource_id']] ?? 0) + 1;
            }
        }

        $weight = (int) config('planning.weights.even_nights', 1);
        $policy = $this->sharePolicy();
        $variancePenalty = $this->variancePenalty($this->varianceCounts($problem, $counts, $totals, $policy));
        $nightSharePenalty = $this->nightSharePenalty($counts, $totals, $policy);

        return new ScoreComponent($this->code(), 'Bilans dniówek i nocek', ($variancePenalty + $nightSharePenalty) * $weight, $weight, false, [
            'night_counts' => $counts,
            'assignment_counts' => $totals,
            'variance_penalty' => $variancePenalty,
            'night_share_penalty' => $nightSharePenalty,
            'min_night_share_percent' => $policy['min'],
            'max_night_share_percent' => $policy['max'],
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

    private function varianceCounts(PlanningProblem $problem, array $counts, array $totals, array $policy): array
    {
        return array_filter($counts, function (int $count, int $resourceId) use ($problem, $totals, $policy): bool {
            $resource = $problem->resources[$resourceId] ?? [];

            return ($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage'
                || ($totals[$resourceId] ?? 0) >= $policy['min_assignments'];
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function nightSharePenalty(array $counts, array $totals, array $policy): int
    {
        $penalty = 0;
        foreach ($totals as $resourceId => $total) {
            if ($total < $policy['min_assignments']) {
                continue;
            }

            $nightPercent = (int) round((($counts[$resourceId] ?? 0) / $total) * 100);
            $underPreferred = max(0, $policy['min'] - $nightPercent);
            $overPreferred = max(0, $nightPercent - $policy['max']);
            $penalty += ($underPreferred ** 2) * 10;
            $penalty += ($overPreferred ** 2) * 25;
        }

        return $penalty;
    }

    private function sharePolicy(): array
    {
        $metadata = collect(config('planning.rules', []))->firstWhere('code', 'even_nights')['metadata'] ?? [];
        if (Schema::hasTable('planning_rule_settings')) {
            $row = DB::table('planning_rule_settings')->where('code', 'even_nights')->first(['metadata']);
            $metadata = array_replace($metadata, json_decode($row?->metadata ?? '[]', true) ?: []);
        }

        return [
            'min' => (int) ($metadata['min_night_share_percent'] ?? 25),
            'max' => (int) ($metadata['max_night_share_percent'] ?? 60),
            'min_assignments' => max(1, (int) ($metadata['min_assignments_for_share'] ?? 4)),
        ];
    }
}
