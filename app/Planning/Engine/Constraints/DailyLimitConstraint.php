<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class DailyLimitConstraint extends AbstractConstraint implements ConstraintInterface
{
    public function code(): string
    {
        return 'daily_limit_exceeded';
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $minutes = [];
        foreach (ScheduleFacts::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! $slot = $problem->slot($gene['slot_id'])) {
                continue;
            }
            $day = CarbonImmutable::parse($slot['starts_at'])->toDateString();
            $minutes[$gene['resource_id']][$day] = ($minutes[$gene['resource_id']][$day] ?? 0) + (int) $slot['duration_minutes'];
        }

        $violations = [];
        foreach ($minutes as $resourceId => $byDay) {
            $limit = (int) ($problem->limitsByResource[$resourceId]['max_minutes_per_day'] ?? 0);
            foreach ($byDay as $day => $value) {
                if ($limit > 0 && $value > $limit) {
                    $violations[] = $this->violation('Przekroczono dzienny limit minut.', $resourceId, null, ['day' => $day, 'minutes' => $value, 'limit' => $limit]);
                }
            }
        }

        return $violations;
    }
}
