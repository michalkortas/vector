<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class SameResourceStreakScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'avoid_same_resource_streaks';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $rows = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            $rowKey = ((int) $slot['planning_unit_id']).':'.((int) ($slot['shift_template_id'] ?? 0));
            $rows[$rowKey][] = [
                'resource_id' => $gene['resource_id'],
                'starts_at' => $slot['starts_at'],
            ];
        }

        $rowPenaltyUnits = 0;
        $rowLongestStreak = 0;
        foreach ($rows as $assignments) {
            usort($assignments, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
            $streakLength = 1;
            for ($i = 1; $i < count($assignments); $i++) {
                $daysBetween = CarbonImmutable::parse($assignments[$i - 1]['starts_at'])
                    ->startOfDay()
                    ->diffInDays(CarbonImmutable::parse($assignments[$i]['starts_at'])->startOfDay());

                if ($assignments[$i]['resource_id'] !== $assignments[$i - 1]['resource_id'] || $daysBetween > 2) {
                    $rowLongestStreak = max($rowLongestStreak, $streakLength);
                    $streakLength = 1;

                    continue;
                }

                $streakLength++;
                $gapMultiplier = $daysBetween <= 1 ? 1 : 2;
                $rowPenaltyUnits += ($streakLength - 1) * ($streakLength - 1) * $gapMultiplier;
            }
            $rowLongestStreak = max($rowLongestStreak, $streakLength);
        }

        [$resourcePenaltyUnits, $resourceLongestStreak] = $this->resourceDayStreakPenalty($problem, $chromosome);
        $penaltyUnits = $rowPenaltyUnits + $resourcePenaltyUnits;
        $weight = (int) config('planning.weights.avoid_same_resource_streaks', 80000);

        return new ScoreComponent($this->code(), 'Unikaj serii tej samej osoby', $penaltyUnits * $weight, $weight, false, [
            'count' => $penaltyUnits,
            'row_penalty_units' => $rowPenaltyUnits,
            'resource_penalty_units' => $resourcePenaltyUnits,
            'longest_streak' => max($rowLongestStreak, $resourceLongestStreak),
            'row_longest_streak' => $rowLongestStreak,
            'resource_longest_streak' => $resourceLongestStreak,
        ]);
    }

    private function resourceDayStreakPenalty(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $daysByResource = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }

            $day = CarbonImmutable::parse($slot['starts_at'])->toDateString();
            $daysByResource[$gene['resource_id']][$day] = $day;
        }

        $penaltyUnits = 0;
        $longestStreak = 0;
        foreach ($daysByResource as $days) {
            sort($days);
            $streakLength = 1;
            for ($i = 1; $i < count($days); $i++) {
                $daysBetween = CarbonImmutable::parse($days[$i - 1])
                    ->startOfDay()
                    ->diffInDays(CarbonImmutable::parse($days[$i])->startOfDay());

                if ($daysBetween > 1) {
                    $longestStreak = max($longestStreak, $streakLength);
                    $streakLength = 1;

                    continue;
                }

                $streakLength++;
                $penaltyUnits += ($streakLength - 1) * ($streakLength - 1);
            }
            $longestStreak = max($longestStreak, $streakLength);
        }

        return [$penaltyUnits, $longestStreak];
    }
}
