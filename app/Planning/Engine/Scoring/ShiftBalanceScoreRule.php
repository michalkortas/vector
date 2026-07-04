<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ShiftBalanceScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'shift_balance';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $groupCounts = [];
        $totals = [];
        $policy = $this->balancePolicy();

        foreach ($problem->resources as $resourceId => $resource) {
            if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                continue;
            }
            $groupCounts[$resourceId] = array_fill_keys($policy['groups'], 0);
            $totals[$resourceId] = 0;
        }

        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            $group = $this->shiftGroup($slot, $policy);
            if (! in_array($group, $policy['groups'], true)) {
                continue;
            }

            $groupCounts[$gene['resource_id']] = $groupCounts[$gene['resource_id']] ?? array_fill_keys($policy['groups'], 0);
            $groupCounts[$gene['resource_id']][$group] = ($groupCounts[$gene['resource_id']][$group] ?? 0) + 1;
            $totals[$gene['resource_id']] = ($totals[$gene['resource_id']] ?? 0) + 1;
        }

        $weight = (int) config('planning.weights.shift_balance', 1);
        $variancePenalty = $this->variancePenalty($this->varianceCountsByGroup($problem, $groupCounts, $totals, $policy));
        $sharePenalty = $this->sharePenalty($groupCounts, $totals, $policy);

        return new ScoreComponent($this->code(), 'Bilans zmian', ($variancePenalty + $sharePenalty) * $weight, $weight, false, [
            'group_counts' => $groupCounts,
            'assignment_counts' => $totals,
            'variance_penalty' => $variancePenalty,
            'share_penalty' => $sharePenalty,
            'balanced_shift_groups' => $policy['groups'],
            'min_share_percent_by_group' => $policy['min_by_group'],
            'max_share_percent_by_group' => $policy['max_by_group'],
        ]);
    }

    private function variancePenalty(array $countsByGroup): int
    {
        $penalty = 0;
        foreach ($countsByGroup as $counts) {
            if ($counts === []) {
                continue;
            }

            $avg = array_sum($counts) / count($counts);
            $penalty += (int) array_sum(array_map(fn (int $count): int => (int) (($count - $avg) ** 2 * 100), $counts));
        }

        return $penalty;
    }

    private function varianceCountsByGroup(PlanningProblem $problem, array $groupCounts, array $totals, array $policy): array
    {
        $eligibleCounts = array_filter($groupCounts, function (array $counts, int $resourceId) use ($problem, $totals, $policy): bool {
            $resource = $problem->resources[$resourceId] ?? [];

            return ($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage'
                || ($totals[$resourceId] ?? 0) >= $policy['min_assignments'];
        }, ARRAY_FILTER_USE_BOTH);

        $countsByGroup = [];
        foreach ($policy['groups'] as $group) {
            $countsByGroup[$group] = [];
            foreach ($eligibleCounts as $resourceId => $counts) {
                $countsByGroup[$group][$resourceId] = (int) ($counts[$group] ?? 0);
            }
        }

        return $countsByGroup;
    }

    private function sharePenalty(array $groupCounts, array $totals, array $policy): int
    {
        $penalty = 0;
        foreach ($totals as $resourceId => $total) {
            if ($total < $policy['min_assignments']) {
                continue;
            }

            foreach ($policy['groups'] as $group) {
                $sharePercent = (int) round(((int) ($groupCounts[$resourceId][$group] ?? 0) / $total) * 100);
                $underPreferred = max(0, ((int) ($policy['min_by_group'][$group] ?? 0)) - $sharePercent);
                $overPreferred = max(0, $sharePercent - ((int) ($policy['max_by_group'][$group] ?? 100)));
                $penalty += ($underPreferred ** 2) * 10;
                $penalty += ($overPreferred ** 2) * 25;
            }
        }

        return $penalty;
    }

    private function balancePolicy(): array
    {
        $metadata = collect(config('planning.rules', []))->firstWhere('code', 'shift_balance')['metadata'] ?? [];
        if (Schema::hasTable('planning_rule_settings')) {
            $row = DB::table('planning_rule_settings')->where('code', 'shift_balance')->first(['metadata']);
            $metadata = array_replace($metadata, json_decode($row?->metadata ?? '[]', true) ?: []);
        }

        $groups = array_values(array_filter($metadata['balanced_shift_groups'] ?? ['day', 'night'], fn ($group): bool => is_string($group) && $group !== ''));
        $minByGroup = $metadata['min_share_percent_by_group'] ?? [];
        $maxByGroup = $metadata['max_share_percent_by_group'] ?? [];
        if (isset($metadata['min_night_share_percent']) && ! isset($minByGroup['night'])) {
            $minByGroup['night'] = $metadata['min_night_share_percent'];
        }
        if (isset($metadata['max_night_share_percent']) && ! isset($maxByGroup['night'])) {
            $maxByGroup['night'] = $metadata['max_night_share_percent'];
        }

        return [
            'groups' => $groups === [] ? ['day', 'night'] : $groups,
            'shift_code_groups' => $metadata['shift_code_groups'] ?? ['DAY_12H' => 'day', 'NIGHT_12H' => 'night'],
            'min_by_group' => $minByGroup,
            'max_by_group' => $maxByGroup,
            'min_assignments' => max(1, (int) ($metadata['min_assignments_for_share'] ?? 4)),
        ];
    }

    private function shiftGroup(array $slot, array $policy): ?string
    {
        $metadataGroup = $slot['metadata']['shift']['balance_group'] ?? $slot['metadata']['balance_group'] ?? null;
        if (is_string($metadataGroup) && $metadataGroup !== '') {
            return $metadataGroup;
        }

        $shiftCode = (string) ($slot['shift_code'] ?? '');
        if (isset($policy['shift_code_groups'][$shiftCode])) {
            return (string) $policy['shift_code_groups'][$shiftCode];
        }

        return str_contains($shiftCode, 'NIGHT') ? 'night' : (str_contains($shiftCode, 'DAY') ? 'day' : null);
    }
}
