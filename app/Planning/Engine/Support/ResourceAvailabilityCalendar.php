<?php

namespace App\Planning\Engine\Support;

use App\Planning\Domain\DTO\PlanningProblem;
use Carbon\CarbonImmutable;

final class ResourceAvailabilityCalendar
{
    public static function blocksResource(PlanningProblem $problem, int $resourceId, array $slot): bool
    {
        foreach ($problem->availabilityRules[$resourceId] ?? [] as $rule) {
            if (! in_array($rule['rule_type'], ['unavailable', 'blocked'], true)) {
                continue;
            }
            if (! self::isEffectiveForSlot($rule, $slot)) {
                continue;
            }
            if (self::overlapsRuleWindow($rule, $slot)) {
                return true;
            }
        }

        return false;
    }

    private static function isEffectiveForSlot(array $rule, array $slot): bool
    {
        $startsAt = CarbonImmutable::parse($slot['starts_at']);
        $endsAt = CarbonImmutable::parse($slot['ends_at']);

        if ($rule['effective_from'] !== null && $endsAt <= CarbonImmutable::parse($rule['effective_from'])->startOfDay()) {
            return false;
        }
        if ($rule['effective_to'] !== null && $startsAt >= CarbonImmutable::parse($rule['effective_to'])->addDay()->startOfDay()) {
            return false;
        }

        return true;
    }

    private static function overlapsRuleWindow(array $rule, array $slot): bool
    {
        $slotStartsAt = CarbonImmutable::parse($slot['starts_at']);
        $slotEndsAt = CarbonImmutable::parse($slot['ends_at']);

        for ($day = $slotStartsAt->startOfDay(); $day < $slotEndsAt; $day = $day->addDay()) {
            if ($rule['day_of_week'] !== null && $day->isoWeekday() !== (int) $rule['day_of_week']) {
                continue;
            }

            $windowStartsAt = $rule['start_time'] ? CarbonImmutable::parse($day->toDateString().' '.$rule['start_time']) : $day;
            $windowEndsAt = $rule['end_time'] ? CarbonImmutable::parse($day->toDateString().' '.$rule['end_time']) : $day->addDay();
            if ($windowEndsAt <= $windowStartsAt) {
                $windowEndsAt = $windowEndsAt->addDay();
            }

            if (ScheduleFacts::overlaps($slotStartsAt, $slotEndsAt, $windowStartsAt, $windowEndsAt)) {
                return true;
            }
        }

        return false;
    }
}
