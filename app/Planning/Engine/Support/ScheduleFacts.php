<?php

namespace App\Planning\Engine\Support;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use Carbon\CarbonImmutable;

final class ScheduleFacts
{
    public static function isNightShift(?array $slot): bool
    {
        if ($slot === null) {
            return false;
        }

        $metadata = is_array($slot['metadata'] ?? null) ? $slot['metadata'] : [];
        $shiftMetadata = is_array($metadata['shift'] ?? null) ? $metadata['shift'] : [];
        $balanceGroup = $shiftMetadata['balance_group'] ?? $metadata['balance_group'] ?? null;

        if (is_string($balanceGroup)) {
            return $balanceGroup === 'night';
        }

        return str_contains((string) ($slot['shift_code'] ?? ''), 'NIGHT');
    }

    public static function genes(ScheduleChromosome $chromosome): array
    {
        $rows = [];
        foreach ($chromosome->genes as $key => $resourceId) {
            [$slotId, $position] = array_map('intval', explode(':', $key));
            $rows[] = ['key' => $key, 'slot_id' => $slotId, 'position' => $position, 'resource_id' => $resourceId];
        }

        return $rows;
    }

    public static function overlaps(string|CarbonImmutable $aStart, string|CarbonImmutable $aEnd, string|CarbonImmutable $bStart, string|CarbonImmutable $bEnd): bool
    {
        $aStart = $aStart instanceof CarbonImmutable ? $aStart : CarbonImmutable::parse($aStart);
        $aEnd = $aEnd instanceof CarbonImmutable ? $aEnd : CarbonImmutable::parse($aEnd);
        $bStart = $bStart instanceof CarbonImmutable ? $bStart : CarbonImmutable::parse($bStart);
        $bEnd = $bEnd instanceof CarbonImmutable ? $bEnd : CarbonImmutable::parse($bEnd);

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    public static function assignmentsByResource(PlanningProblem $problem, ScheduleChromosome $chromosome): array
    {
        $byResource = [];
        foreach (self::genes($chromosome) as $gene) {
            if ($gene['resource_id'] === null || ! isset($problem->demandSlots[$gene['slot_id']])) {
                continue;
            }
            $slot = $problem->demandSlots[$gene['slot_id']];
            $byResource[$gene['resource_id']][] = [
                ...$gene,
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
                'duration_minutes' => (int) $slot['duration_minutes'],
            ];
        }

        foreach ($byResource as &$assignments) {
            usort($assignments, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
        }

        return $byResource;
    }

    public static function paidAbsenceMinutesByResource(PlanningProblem $problem): array
    {
        $minutes = [];
        foreach ($problem->absences as $resourceId => $absences) {
            foreach ($absences as $absence) {
                if ($absence['counts_as_work_time']) {
                    $minutes[$resourceId] = ($minutes[$resourceId] ?? 0) + (int) ($absence['nominal_minutes'] ?? 0);
                }
            }
        }

        return $minutes;
    }
}
