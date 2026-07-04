<?php

namespace App\Planning\Engine\Repair;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Contracts\ScheduleRepairerInterface;
use App\Planning\Engine\Support\HolidayCalendar;
use App\Planning\Engine\Support\ResourceAvailabilityCalendar;
use App\Planning\Engine\Support\ScheduleFacts;
use Carbon\CarbonImmutable;

final class DefaultScheduleRepairer implements ScheduleRepairerInterface
{
    public function repair(PlanningProblem $problem, ScheduleChromosome $chromosome, CandidatePool $candidatePool): ScheduleChromosome
    {
        $genes = $chromosome->genes;

        foreach ($problem->lockedAssignments as $key => $resourceId) {
            $genes[$key] = $resourceId;
        }

        $used = [];
        foreach ($this->repairOrder($problem, $genes) as $key => $resourceId) {
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);

            if ($resourceId === null) {
                $genes[$key] = $this->firstCandidate($problem, $candidatePool, $key, $slot, $used);
                if ($genes[$key] !== null && $slot !== null) {
                    $used[$genes[$key]][] = $slot;
                }

                continue;
            }

            $invalid = $slot === null
                || array_diff($problem->requiredSkillsBySlot[$slotId] ?? [], $problem->skillsByResource[$resourceId] ?? []) !== []
                || HolidayCalendar::blocksResource($problem, $resourceId, $slot)
                || ResourceAvailabilityCalendar::blocksResource($problem, $resourceId, $slot)
                || ($problem->unitRuleForSlot($slot, $resourceId)['usage_mode'] === 'excluded');

            if ($slot !== null) {
                foreach ($problem->absences[$resourceId] ?? [] as $absence) {
                    $invalid = $invalid || ($absence['blocks_planning'] && ScheduleFacts::overlaps($slot['starts_at'], $slot['ends_at'], $absence['starts_at'], $absence['ends_at']));
                }
            }

            if ($invalid || $this->hasOverlap($slot, $resourceId, $used) || $this->hasMinimumRestConflict($problem, $slot, $resourceId, $used) || ! $this->withinLimits($problem, $slot, $resourceId, $used)) {
                $genes[$key] = $this->firstCandidate($problem, $candidatePool, $key, $slot, $used);
            }

            if ($genes[$key] !== null && $slot !== null) {
                $used[$genes[$key]][] = $slot;
            }
        }

        $genes = $this->reduceSameRowStreaks($problem, $candidatePool, $genes);
        $genes = $this->reduceResourceDayStreaks($problem, $candidatePool, $genes);
        $genes = $this->enforceMonthlyNominalLimits($problem, $candidatePool, $genes);

        return new ScheduleChromosome($genes);
    }

    private function repairOrder(PlanningProblem $problem, array $genes): array
    {
        uksort($genes, function (string $a, string $b) use ($problem): int {
            [$slotA] = array_map('intval', explode(':', $a));
            [$slotB] = array_map('intval', explode(':', $b));
            $deferA = $this->isPrimarySlotWithSubstitutionPolicy($problem, $slotA);
            $deferB = $this->isPrimarySlotWithSubstitutionPolicy($problem, $slotB);

            return [$deferA ? 1 : 0, $slotA] <=> [$deferB ? 1 : 0, $slotB];
        });

        return $genes;
    }

    private function reduceResourceDayStreaks(PlanningProblem $problem, CandidatePool $pool, array $genes): array
    {
        for ($pass = 0; $pass < 2; $pass++) {
            $changed = false;
            foreach ($this->resourceDayAssignments($problem, $genes) as $assignments) {
                $previous = null;
                foreach ($assignments as $assignment) {
                    if ($previous === null) {
                        $previous = $assignment;

                        continue;
                    }

                    $daysBetween = CarbonImmutable::parse($previous['slot']['starts_at'])
                        ->startOfDay()
                        ->diffInDays(CarbonImmutable::parse($assignment['slot']['starts_at'])->startOfDay());
                    if ($daysBetween > 1) {
                        $previous = $assignment;

                        continue;
                    }
                    if ($daysBetween === 0 || array_key_exists($assignment['key'], $problem->lockedAssignments)) {
                        $previous = $assignment;

                        continue;
                    }

                    $used = $this->usedSlotsByResourceExcept($problem, $genes, $assignment['key']);
                    $replacement = $this->firstCandidate($problem, $pool, $assignment['key'], $assignment['slot'], $used, [$assignment['resource_id']]);
                    if ($replacement !== null) {
                        $genes[$assignment['key']] = $replacement;
                        $changed = true;

                        continue;
                    }

                    $previous = $assignment;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $genes;
    }

    private function isPrimarySlotWithSubstitutionPolicy(PlanningProblem $problem, int $slotId): bool
    {
        $slot = $problem->slot($slotId);
        if ($slot === null) {
            return false;
        }

        foreach ($problem->substitutionPolicies as $policy) {
            if ($policy['effect'] !== 'allow_primary_slot_unassigned') {
                continue;
            }
            if ($policy['primary_planning_unit_id'] !== $slot['planning_unit_id']) {
                continue;
            }
            if ($policy['primary_shift_template_id'] !== null && $policy['primary_shift_template_id'] !== $slot['shift_template_id']) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function reduceSameRowStreaks(PlanningProblem $problem, CandidatePool $pool, array $genes): array
    {
        for ($pass = 0; $pass < 2; $pass++) {
            $changed = false;
            foreach ($this->sameRowAssignments($problem, $genes) as $assignments) {
                $previous = null;
                foreach ($assignments as $assignment) {
                    if ($previous === null) {
                        $previous = $assignment;

                        continue;
                    }

                    $daysBetween = CarbonImmutable::parse($previous['slot']['starts_at'])
                        ->startOfDay()
                        ->diffInDays(CarbonImmutable::parse($assignment['slot']['starts_at'])->startOfDay());
                    if ($assignment['resource_id'] !== $previous['resource_id'] || $daysBetween > 2) {
                        $previous = $assignment;

                        continue;
                    }
                    if (array_key_exists($assignment['key'], $problem->lockedAssignments)) {
                        $previous = $assignment;

                        continue;
                    }

                    $used = $this->usedSlotsByResourceExcept($problem, $genes, $assignment['key']);
                    $replacement = $this->firstCandidate($problem, $pool, $assignment['key'], $assignment['slot'], $used, [$assignment['resource_id']]);
                    if ($replacement !== null) {
                        $genes[$assignment['key']] = $replacement;
                        $changed = true;
                        $previous = [
                            ...$assignment,
                            'resource_id' => $replacement,
                        ];
                    } else {
                        $previous = $assignment;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $genes;
    }

    private function sameRowAssignments(PlanningProblem $problem, array $genes): array
    {
        $rows = [];
        foreach ($genes as $key => $resourceId) {
            if ($resourceId === null) {
                continue;
            }
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);
            if ($slot === null) {
                continue;
            }

            $rowKey = ((int) $slot['planning_unit_id']).':'.((int) ($slot['shift_template_id'] ?? 0));
            $rows[$rowKey][] = [
                'key' => $key,
                'resource_id' => $resourceId,
                'slot' => $slot,
            ];
        }

        foreach ($rows as &$assignments) {
            usort($assignments, fn (array $a, array $b): int => strcmp($a['slot']['starts_at'], $b['slot']['starts_at']));
        }

        return $rows;
    }

    private function resourceDayAssignments(PlanningProblem $problem, array $genes): array
    {
        $byResource = [];
        foreach ($genes as $key => $resourceId) {
            if ($resourceId === null) {
                continue;
            }
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);
            if ($slot === null) {
                continue;
            }

            $byResource[$resourceId][] = [
                'key' => $key,
                'resource_id' => $resourceId,
                'slot' => $slot,
            ];
        }

        foreach ($byResource as &$assignments) {
            usort($assignments, fn (array $a, array $b): int => strcmp($a['slot']['starts_at'], $b['slot']['starts_at']));
        }

        return $byResource;
    }

    private function enforceMonthlyNominalLimits(PlanningProblem $problem, CandidatePool $pool, array $genes): array
    {
        $paidAbsenceMinutes = ScheduleFacts::paidAbsenceMinutesByResource($problem);

        for ($pass = 0; $pass < 3; $pass++) {
            $planned = $this->plannedMinutesByResource($problem, $genes);
            $changed = false;

            foreach ($planned as $resourceId => $minutes) {
                $resource = $problem->resources[$resourceId] ?? [];
                $target = (int) ($problem->limitsByResource[$resourceId]['target_minutes_per_month'] ?? 0);
                if ($target <= 0 || ($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                    continue;
                }

                $over = $minutes + ($paidAbsenceMinutes[$resourceId] ?? 0) - $target;
                if ($over <= 0) {
                    continue;
                }

                foreach ($this->assignmentKeysForResource($problem, $genes, $resourceId) as $key) {
                    if (array_key_exists($key, $problem->lockedAssignments)) {
                        continue;
                    }
                    [$slotId] = array_map('intval', explode(':', $key));
                    $slot = $problem->slot($slotId);
                    if ($slot === null) {
                        continue;
                    }

                    $used = $this->usedSlotsByResourceExcept($problem, $genes, $key);
                    $replacement = $this->firstCandidate($problem, $pool, $key, $slot, $used, [$resourceId]);
                    if ($replacement === null) {
                        continue;
                    }

                    $genes[$key] = $replacement;
                    $changed = true;
                    break;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $genes;
    }

    private function plannedMinutesByResource(PlanningProblem $problem, array $genes): array
    {
        $planned = [];
        foreach ($genes as $key => $resourceId) {
            if ($resourceId === null) {
                continue;
            }
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);
            if ($slot !== null) {
                $planned[$resourceId] = ($planned[$resourceId] ?? 0) + (int) $slot['duration_minutes'];
            }
        }

        return $planned;
    }

    private function assignmentKeysForResource(PlanningProblem $problem, array $genes, int $resourceId): array
    {
        $assignments = [];
        foreach ($genes as $key => $assignedResourceId) {
            if ($assignedResourceId !== $resourceId) {
                continue;
            }
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);
            if ($slot !== null) {
                $assignments[] = ['key' => $key, 'starts_at' => $slot['starts_at']];
            }
        }

        usort($assignments, fn (array $a, array $b): int => strcmp($b['starts_at'], $a['starts_at']));

        return array_column($assignments, 'key');
    }

    private function usedSlotsByResourceExcept(PlanningProblem $problem, array $genes, string $exceptKey): array
    {
        $used = [];
        foreach ($genes as $key => $resourceId) {
            if ($key === $exceptKey || $resourceId === null) {
                continue;
            }
            [$slotId] = array_map('intval', explode(':', $key));
            $slot = $problem->slot($slotId);
            if ($slot !== null) {
                $used[$resourceId][] = $slot;
            }
        }

        return $used;
    }

    private function firstCandidate(PlanningProblem $problem, CandidatePool $pool, string $key, ?array $slot, array $used, array $excludedResourceIds = []): ?int
    {
        if ($slot === null) {
            return null;
        }
        $viable = [];
        foreach ($pool->candidates($key) as $candidate) {
            if (in_array((int) $candidate['resource_id'], $excludedResourceIds, true)) {
                continue;
            }
            if (
                ! HolidayCalendar::blocksResource($problem, $candidate['resource_id'], $slot)
                && ! ResourceAvailabilityCalendar::blocksResource($problem, $candidate['resource_id'], $slot)
                && ! $this->hasOverlap($slot, $candidate['resource_id'], $used)
                && ! $this->hasMinimumRestConflict($problem, $slot, $candidate['resource_id'], $used)
                && $this->withinLimits($problem, $slot, $candidate['resource_id'], $used)
            ) {
                $viable[] = [
                    ...$candidate,
                    'repair_score' => $this->candidateRepairScore($slot, $candidate, $used),
                ];
            }
        }

        if ($viable === []) {
            return null;
        }

        usort($viable, fn (array $a, array $b): int => [$a['repair_score'], $a['penalty'], $a['priority'], $a['resource_id']] <=> [$b['repair_score'], $b['penalty'], $b['priority'], $b['resource_id']]);

        return $viable[0]['resource_id'];
    }

    private function candidateRepairScore(array $slot, array $candidate, array $used): int
    {
        $score = ((int) $candidate['penalty'] * 1000) + (int) $candidate['priority'];
        $resourceId = (int) $candidate['resource_id'];
        $slotDay = CarbonImmutable::parse($slot['starts_at'])->startOfDay();
        $slotShiftId = (int) ($slot['shift_template_id'] ?? 0);
        $slotUnitId = (int) $slot['planning_unit_id'];

        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            $usedDay = CarbonImmutable::parse($usedSlot['starts_at'])->startOfDay();
            $daysBetween = abs($usedDay->diffInDays($slotDay, false));
            if ($daysBetween > 2) {
                continue;
            }

            $sameShift = $slotShiftId === (int) ($usedSlot['shift_template_id'] ?? 0);
            $sameRow = $sameShift && $slotUnitId === (int) $usedSlot['planning_unit_id'];

            if ($sameRow && $daysBetween <= 1) {
                $score += 1_000_000;
            } elseif ($sameRow) {
                $score += 250_000;
            } elseif ($sameShift && $daysBetween <= 1) {
                $score += 100_000;
            }
            $score += (3 - $daysBetween) * 25_000;
        }

        $score += $this->resourceDayStreakRepairPenalty($slot, $resourceId, $used);
        $score += $this->shiftBalanceRepairPenalty($slot, $resourceId, $used);

        return $score;
    }

    private function resourceDayStreakRepairPenalty(array $slot, int $resourceId, array $used): int
    {
        $days = [CarbonImmutable::parse($slot['starts_at'])->toDateString()];
        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            $days[] = CarbonImmutable::parse($usedSlot['starts_at'])->toDateString();
        }

        $days = array_values(array_unique($days));
        sort($days);

        $penalty = 0;
        $streakLength = 1;
        for ($i = 1; $i < count($days); $i++) {
            $daysBetween = CarbonImmutable::parse($days[$i - 1])
                ->startOfDay()
                ->diffInDays(CarbonImmutable::parse($days[$i])->startOfDay());
            if ($daysBetween > 1) {
                $streakLength = 1;

                continue;
            }

            $streakLength++;
            $penalty += ($streakLength - 1) * ($streakLength - 1) * 200_000;
        }

        return $penalty;
    }

    private function shiftBalanceRepairPenalty(array $slot, int $resourceId, array $used): int
    {
        $policy = $this->shiftBalancePolicy();
        $slotGroup = $this->shiftBalanceGroup($slot, $policy);
        if (! in_array($slotGroup, $policy['groups'], true)) {
            return 0;
        }

        $total = 1;
        $groupCounts = [$slotGroup => 1];
        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            $usedGroup = $this->shiftBalanceGroup($usedSlot, $policy);
            if (! in_array($usedGroup, $policy['groups'], true)) {
                continue;
            }

            $total++;
            $groupCounts[$usedGroup] = ($groupCounts[$usedGroup] ?? 0) + 1;
        }

        if ($total < $policy['min_assignments']) {
            return 0;
        }

        $penalty = 0;
        foreach ($policy['groups'] as $group) {
            $sharePercent = (int) round(((int) ($groupCounts[$group] ?? 0) / $total) * 100);
            $underPreferred = max(0, ((int) ($policy['min_by_group'][$group] ?? 0)) - $sharePercent);
            $overPreferred = max(0, $sharePercent - ((int) ($policy['max_by_group'][$group] ?? 100)));
            $penalty += ($underPreferred ** 2) * 2_000 + ($overPreferred ** 2) * 5_000;
        }

        return $penalty;
    }

    private function shiftBalancePolicy(): array
    {
        $metadata = collect(config('planning.rules', []))->firstWhere('code', 'shift_balance')['metadata'] ?? [];
        $groups = array_values(array_filter($metadata['balanced_shift_groups'] ?? ['day', 'night'], fn ($group): bool => is_string($group) && $group !== ''));

        return [
            'groups' => $groups === [] ? ['day', 'night'] : $groups,
            'shift_code_groups' => $metadata['shift_code_groups'] ?? ['DAY_12H' => 'day', 'NIGHT_12H' => 'night'],
            'min_by_group' => $metadata['min_share_percent_by_group'] ?? ['night' => 25],
            'max_by_group' => $metadata['max_share_percent_by_group'] ?? ['night' => 60],
            'min_assignments' => max(1, (int) ($metadata['min_assignments_for_share'] ?? 3)),
        ];
    }

    private function shiftBalanceGroup(array $slot, array $policy): ?string
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

    private function hasOverlap(array $slot, int $resourceId, array $used): bool
    {
        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            if (ScheduleFacts::overlaps($slot['starts_at'], $slot['ends_at'], $usedSlot['starts_at'], $usedSlot['ends_at'])) {
                return true;
            }
        }

        return false;
    }

    private function hasMinimumRestConflict(PlanningProblem $problem, array $slot, int $resourceId, array $used): bool
    {
        $minRest = (int) ($problem->limitsByResource[$resourceId]['min_rest_minutes'] ?? 0);
        if ($minRest <= 0) {
            return false;
        }

        $startsAt = CarbonImmutable::parse($slot['starts_at']);
        $endsAt = CarbonImmutable::parse($slot['ends_at']);
        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            $usedStartsAt = CarbonImmutable::parse($usedSlot['starts_at']);
            $usedEndsAt = CarbonImmutable::parse($usedSlot['ends_at']);
            if ($usedEndsAt <= $startsAt && $usedEndsAt->diffInMinutes($startsAt, false) < $minRest) {
                return true;
            }
            if ($endsAt <= $usedStartsAt && $endsAt->diffInMinutes($usedStartsAt, false) < $minRest) {
                return true;
            }
        }

        return false;
    }

    private function withinLimits(PlanningProblem $problem, array $slot, int $resourceId, array $used): bool
    {
        $paidAbsenceMinutes = ScheduleFacts::paidAbsenceMinutesByResource($problem);
        $day = substr((string) $slot['starts_at'], 0, 10);
        $daily = (int) $slot['duration_minutes'];
        $monthly = (int) $slot['duration_minutes'];
        foreach ($used[$resourceId] ?? [] as $usedSlot) {
            $monthly += (int) $usedSlot['duration_minutes'];
            if (substr((string) $usedSlot['starts_at'], 0, 10) === $day) {
                $daily += (int) $usedSlot['duration_minutes'];
            }
        }

        $limits = $problem->limitsByResource[$resourceId] ?? [];
        $dailyLimit = (int) ($limits['max_minutes_per_day'] ?? 0);
        $monthlyLimit = (int) ($limits['max_minutes_per_month'] ?? 0);
        $target = (int) ($limits['target_minutes_per_month'] ?? 0);
        $resource = $problem->resources[$resourceId] ?? [];
        $withinNominal = true;
        if (($resource['metadata']['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage' && $target > 0) {
            $withinNominal = ($monthly + ($paidAbsenceMinutes[$resourceId] ?? 0)) <= $target;
        }

        return $withinNominal && ($dailyLimit === 0 || $daily <= $dailyLimit) && ($monthlyLimit === 0 || $monthly <= $monthlyLimit);
    }
}
