<?php

namespace App\Planning\Engine\Support;

use App\Planning\Domain\DTO\PlanningProblem;
use Carbon\CarbonImmutable;

final class HolidayCalendar
{
    public static function blocksResource(PlanningProblem $problem, int $resourceId, array $slot): bool
    {
        $resource = $problem->resources[$resourceId] ?? null;
        if ($resource === null) {
            return false;
        }

        foreach ($problem->holidays as $holiday) {
            if (! $holiday['blocks_planning']) {
                continue;
            }
            if (! self::overlapsSlot($holiday['holiday_date'], $slot)) {
                continue;
            }
            if ($holiday['scope'] === 'global') {
                return true;
            }
            if ($holiday['scope'] === 'resource' && (int) $holiday['resource_id'] === $resourceId) {
                return true;
            }
            if ($holiday['scope'] === 'resource_group' && (int) $holiday['resource_group_id'] === (int) ($resource['resource_group_id'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private static function overlapsSlot(string $holidayDate, array $slot): bool
    {
        $startsAt = CarbonImmutable::parse($holidayDate)->startOfDay();
        $endsAt = $startsAt->addDay();

        return ScheduleFacts::overlaps($slot['starts_at'], $slot['ends_at'], $startsAt, $endsAt);
    }
}
