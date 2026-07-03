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

        $penaltyUnits = 0;
        $longestStreak = 0;
        foreach ($rows as $assignments) {
            usort($assignments, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
            $streakLength = 1;
            for ($i = 1; $i < count($assignments); $i++) {
                $daysBetween = CarbonImmutable::parse($assignments[$i - 1]['starts_at'])
                    ->startOfDay()
                    ->diffInDays(CarbonImmutable::parse($assignments[$i]['starts_at'])->startOfDay());

                if ($assignments[$i]['resource_id'] !== $assignments[$i - 1]['resource_id'] || $daysBetween > 2) {
                    $longestStreak = max($longestStreak, $streakLength);
                    $streakLength = 1;
                    continue;
                }

                $streakLength++;
                $gapMultiplier = $daysBetween <= 1 ? 1 : 2;
                $penaltyUnits += ($streakLength - 1) * ($streakLength - 1) * $gapMultiplier;
            }
            $longestStreak = max($longestStreak, $streakLength);
        }

        $weight = (int) config('planning.weights.avoid_same_resource_streaks', 80000);

        return new ScoreComponent($this->code(), 'Unikaj serii tej samej osoby', $penaltyUnits * $weight, $weight, false, ['count' => $penaltyUnits, 'longest_streak' => $longestStreak]);
    }
}
