<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Engine\Contracts\ScoreRuleInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class NightRecoveryAfterNightScoreRule implements ScoreRuleInterface
{
    public function code(): string
    {
        return 'night_recovery_after_night';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent
    {
        $assignmentsByResource = ScheduleFacts::assignmentsByResource($problem, $chromosome);
        $violations = 0;

        foreach ($assignmentsByResource as $assignments) {
            $workDays = [];
            foreach ($assignments as $assignment) {
                $workDays[substr((string) $assignment['starts_at'], 0, 10)] = true;
            }

            foreach ($assignments as $assignment) {
                $slot = $problem->slot($assignment['slot_id']);
                if (! ScheduleFacts::isNightShift($slot)) {
                    continue;
                }

                $recoveryDay = CarbonImmutable::parse($assignment['starts_at'])->addDay()->toDateString();
                if (isset($workDays[$recoveryDay])) {
                    $violations++;
                }
            }
        }

        $weight = (int) config('planning.weights.night_recovery_after_night', 12000);

        return new ScoreComponent($this->code(), 'Po nocce preferuj dzień wolny', $violations * $weight, $weight, false, ['count' => $violations]);
    }
}
