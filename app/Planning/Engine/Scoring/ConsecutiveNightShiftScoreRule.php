<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class ConsecutiveNightShiftScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'consecutive_nights';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $violations = 0;
        foreach (ScheduleFacts::assignmentsByResource($problem, $chromosome) as $assignments) {
            $nightDays = [];
            foreach ($assignments as $assignment) {
                $slot = $problem->slot($assignment['slot_id']);
                if (($slot['shift_code'] ?? '') === 'NIGHT_12H') {
                    $nightDays[substr((string) $assignment['starts_at'], 0, 10)] = true;
                }
            }

            foreach (array_keys($nightDays) as $nightDay) {
                if (isset($nightDays[CarbonImmutable::parse($nightDay)->addDay()->toDateString()])) {
                    $violations++;
                }
            }
        }

        $weight = (int) config('planning.weights.consecutive_nights', 20000);

        return new ScoreComponent($this->code(), 'Unikaj nocek pod rząd', $violations * $weight, $weight, false, ['count' => $violations]);
    }
}
