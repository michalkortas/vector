<?php

namespace App\Planning\Infrastructure;

use App\Planning\Domain\DTO\PlanningResult;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Domain\DTO\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EloquentPlanningResultPersister
{
    public function persist(int $planningRunId, int $planningPeriodId, PlanningResult $result): void
    {
        DB::transaction(function () use ($planningRunId, $planningPeriodId, $result): void {
            DB::table('assignments')->where('planning_run_id', $planningRunId)->delete();
            DB::table('planning_run_score_components')->where('planning_run_id', $planningRunId)->delete();
            DB::table('planning_run_violations')->where('planning_run_id', $planningRunId)->delete();

            foreach ($result->chromosome->genes as $key => $resourceId) {
                [$slotId, $position] = array_map('intval', explode(':', $key));
                $slot = DB::table('demand_slots')->where('id', $slotId)->first(['starts_at', 'ends_at', 'duration_minutes']);
                DB::table('assignments')->insert([
                    'planning_period_id' => $planningPeriodId,
                    'demand_slot_id' => $slotId,
                    'slot_position' => $position,
                    'segment_position' => 1,
                    'resource_id' => $resourceId,
                    'planning_run_id' => $planningRunId,
                    'starts_at' => $slot?->starts_at,
                    'ends_at' => $slot?->ends_at,
                    'duration_minutes' => $slot?->duration_minutes,
                    'source' => 'generated',
                    'is_locked' => false,
                    'metadata' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->ensureContractMinimumAssignments($planningRunId, $planningPeriodId);
            $completionViolations = $this->completeEmployeeNominals($planningRunId, $planningPeriodId);
            $this->reduceContractUsageWithFlexResourcePrefixes($planningRunId, $planningPeriodId);

            foreach ($result->score->components as $component) {
                /** @var ScoreComponent $component */
                DB::table('planning_run_score_components')->insert([
                    'planning_run_id' => $planningRunId,
                    'code' => $component->code,
                    'label' => $component->label,
                    'score' => $component->score,
                    'weight' => $component->weight,
                    'hard' => $component->hard,
                    'metadata' => json_encode($component->metadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($result->score->violations as $violation) {
                /** @var Violation $violation */
                DB::table('planning_run_violations')->insert([
                    'planning_run_id' => $planningRunId,
                    'code' => $violation->code,
                    'severity' => $violation->severity->value,
                    'message' => $violation->message,
                    'resource_id' => $violation->resourceId,
                    'demand_slot_id' => $violation->demandSlotId,
                    'metadata' => json_encode($violation->metadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($completionViolations as $violation) {
                DB::table('planning_run_violations')->insert([
                    'planning_run_id' => $planningRunId,
                    'code' => $violation['code'],
                    'severity' => $violation['severity'],
                    'message' => $violation['message'],
                    'resource_id' => $violation['resource_id'],
                    'demand_slot_id' => null,
                    'metadata' => json_encode($violation),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $completionHardViolations = count(array_filter($completionViolations, fn (array $violation): bool => $violation['severity'] === 'hard'));
            $completionSoftViolations = count($completionViolations) - $completionHardViolations;

            DB::table('planning_runs')->where('id', $planningRunId)->update([
                'status' => 'completed',
                'score_total' => $result->score->total,
                'hard_violations_count' => $result->score->hardViolationsCount + $completionHardViolations,
                'soft_violations_count' => $result->score->softViolationsCount + $completionSoftViolations,
                'unassigned_slots_count' => $result->score->unassignedSlotsCount,
                'finished_at' => now(),
                'metadata' => json_encode($result->metadata),
                'updated_at' => now(),
            ]);
        });
    }

    private function completeEmployeeNominals(int $planningRunId, int $planningPeriodId): array
    {
        $flexResourceId = $this->flexResourceId();
        if ($flexResourceId === null) {
            return $this->nominalUnderfillViolations($planningRunId, $planningPeriodId);
        }

        for ($attempt = 0; $attempt < 80; $attempt++) {
            $underfilledResources = $this->underfilledEmploymentResources($planningRunId, $planningPeriodId);
            if ($underfilledResources === []) {
                break;
            }

            $changed = false;
            foreach ($underfilledResources as $underfilled) {
                if ($underfilled['missing_minutes'] <= 0) {
                    continue;
                }
                if ((int) $underfilled['resource_id'] === $flexResourceId) {
                    $missing = min((int) $underfilled['missing_minutes'], $this->maxDemandSlotMinutes($planningPeriodId));
                    $changed = $this->addSupplementaryNominalTopUp(
                        $planningRunId,
                        $planningPeriodId,
                        $flexResourceId,
                        $missing,
                        $this->flexResourceSelfTopUpAllowedShiftCodes($flexResourceId),
                        $this->flexResourceSelfTopUpAllowedUnitCodes($flexResourceId),
                    ) || $changed;

                    continue;
                }

                $changed = $this->splitFlexResourceAssignmentForTopUp($planningRunId, $planningPeriodId, $flexResourceId, $underfilled) || $changed;
            }

            if (! $changed) {
                break;
            }
        }

        return $this->nominalUnderfillViolations($planningRunId, $planningPeriodId);
    }

    private function splitFlexResourceAssignmentForTopUp(int $planningRunId, int $planningPeriodId, int $flexResourceId, array $underfilled): bool
    {
        $missing = min((int) $underfilled['missing_minutes'], $this->maxDemandSlotMinutes($planningPeriodId));
        if ($missing <= 0) {
            return true;
        }

        $resourceId = (int) $underfilled['resource_id'];
        $resourceSkills = $this->skillIdsForResource($resourceId);
        $flexResourceSkills = $this->skillIdsForResource($flexResourceId);
        $assignments = DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->join('resources', 'resources.id', '=', 'assignments.resource_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', '<>', $flexResourceId)
            ->where('assignments.duration_minutes', '>=', $this->minimumEmployeeSegmentMinutes() * 2)
            ->when($this->flexResourcePrimaryUnitIds($flexResourceId) !== [], fn ($query) => $query->whereNotIn('planning_units.id', $this->flexResourcePrimaryUnitIds($flexResourceId)))
            ->get([
                'assignments.*',
                'resources.metadata as resource_metadata',
                'shift_templates.code as shift_code',
                'demand_slots.starts_at as slot_starts_at',
                'demand_slots.ends_at as slot_ends_at',
                'demand_slots.duration_minutes as slot_duration_minutes',
                'demand_slots.metadata as slot_metadata',
            ])
            ->sortBy(fn ($assignment): int => $this->contractReassignmentCandidateScore($planningRunId, $planningPeriodId, $resourceId, $assignment))
            ->values();

        foreach ($assignments as $assignment) {
            $assignedMetadata = json_decode($assignment->resource_metadata ?? '[]', true) ?: [];
            if (($assignedMetadata['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage') {
                continue;
            }
            if ($this->contractAssignmentCount($planningRunId, (int) $assignment->resource_id) <= $this->minimumContractAssignmentsPerActiveResource()) {
                continue;
            }
            if ($this->demandSlotHasNominalSplit($planningRunId, (int) $assignment->demand_slot_id)) {
                continue;
            }

            $requiredSkills = DB::table('demand_slot_required_skill')->where('demand_slot_id', $assignment->demand_slot_id)->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
            if (array_diff($requiredSkills, $resourceSkills) !== []) {
                continue;
            }

            $assignmentStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $assignmentEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
            $partialEmployeeMinutes = $this->employeePartialMinutesForMissing($missing, (int) $assignment->duration_minutes);
            if ($partialEmployeeMinutes !== null && $this->splitContractAssignmentBetweenEmployeeAndFlexResource(
                $planningRunId,
                $planningPeriodId,
                $flexResourceId,
                $resourceId,
                $assignment,
                $partialEmployeeMinutes,
                $resourceSkills,
                $flexResourceSkills,
            )) {
                return true;
            }

            if ($missing >= (int) $assignment->duration_minutes && ! $this->hasAssignmentConflict($planningRunId, $planningPeriodId, $resourceId, $assignmentStart, $assignmentEnd)) {
                DB::table('assignments')->where('id', $assignment->id)->update([
                    'resource_id' => $resourceId,
                    'source' => 'generated_contract_reassigned_for_nominal',
                    'metadata' => json_encode([
                        'segment_kind' => 'contract_reassigned_full',
                        'replaced_contract_resource_id' => (int) $assignment->resource_id,
                    ]),
                    'updated_at' => now(),
                ]);

                return true;
            }

            if ($this->splitContractAssignmentBetweenEmployeeAndFlexResource(
                $planningRunId,
                $planningPeriodId,
                $flexResourceId,
                $resourceId,
                $assignment,
                min($missing, (int) $assignment->duration_minutes),
                $resourceSkills,
                $flexResourceSkills,
            )) {
                return true;
            }
        }

        return false;
    }

    private function employeePartialMinutesForMissing(int $missing, int $assignmentMinutes): ?int
    {
        if ($missing <= 0 || $assignmentMinutes <= $this->minimumEmployeeSegmentMinutes()) {
            return null;
        }

        if ($missing < $assignmentMinutes) {
            return $missing >= $this->minimumEmployeeSegmentMinutes() ? $missing : null;
        }

        $remainder = $missing % $assignmentMinutes;

        return $remainder >= $this->minimumEmployeeSegmentMinutes() ? $remainder : null;
    }

    private function splitContractAssignmentBetweenEmployeeAndFlexResource(
        int $planningRunId,
        int $planningPeriodId,
        int $flexResourceId,
        int $resourceId,
        object $assignment,
        int $employeeMinutes,
        array $resourceSkills,
        array $flexResourceSkills,
    ): bool {
        if (! in_array((string) ($assignment->shift_code ?? ''), $this->flexResourceAllowedShiftCodes($flexResourceId), true)) {
            return false;
        }
        if ($employeeMinutes < $this->minimumEmployeeSegmentMinutes() || $employeeMinutes >= (int) $assignment->duration_minutes) {
            return false;
        }
        if (! $this->resourceHasDemandSlotSkills($flexResourceSkills, (int) $assignment->demand_slot_id)) {
            return false;
        }
        if (! $this->seniorCoverageStaysCoveredAfterResourceChange($planningRunId, $assignment, array_values(array_unique([...$resourceSkills, ...$flexResourceSkills])))) {
            return false;
        }
        if ($this->demandSlotHasNominalSplit($planningRunId, (int) $assignment->demand_slot_id)) {
            return false;
        }

        $assignmentStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
        $assignmentEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
        $employeeStart = $assignmentEnd->subMinutes($employeeMinutes);
        if ($assignmentStart >= $employeeStart) {
            return false;
        }
        if ($this->flexResourceSplitCountForDay($planningRunId, $flexResourceId, $assignmentStart->toDateString()) >= $this->maxFlexResourceSplitsPerDay($flexResourceId)) {
            return false;
        }
        if ($this->hasAssignmentConflict($planningRunId, $planningPeriodId, $resourceId, $employeeStart, $assignmentEnd)) {
            return false;
        }
        if (! $this->flexResourceCanCoverPrefix($planningRunId, $planningPeriodId, $flexResourceId, $assignmentStart, $employeeStart)) {
            return false;
        }

        $this->reduceFlexResourcePrimaryConflicts($planningRunId, $planningPeriodId, $flexResourceId, $assignmentStart, $employeeStart);
        $nextSegment = ((int) DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('demand_slot_id', $assignment->demand_slot_id)
            ->where('slot_position', $assignment->slot_position)
            ->max('segment_position')) + 1;

        DB::table('assignments')->where('id', $assignment->id)->update([
            'resource_id' => $resourceId,
            'starts_at' => $employeeStart->toDateTimeString(),
            'ends_at' => $assignmentEnd->toDateTimeString(),
            'duration_minutes' => $employeeMinutes,
            'source' => 'generated_contract_split_for_nominal',
            'metadata' => json_encode([
                'segment_kind' => 'contract_split_employee_tail',
                'replaced_contract_resource_id' => (int) $assignment->resource_id,
                'flex_resource_id' => $flexResourceId,
            ]),
            'updated_at' => now(),
        ]);

        DB::table('assignments')->insert([
            'planning_period_id' => $planningPeriodId,
            'demand_slot_id' => $assignment->demand_slot_id,
            'slot_position' => $assignment->slot_position,
            'segment_position' => $nextSegment,
            'resource_id' => $flexResourceId,
            'planning_run_id' => $planningRunId,
            'starts_at' => $assignmentStart->toDateTimeString(),
            'ends_at' => $employeeStart->toDateTimeString(),
            'duration_minutes' => $assignmentStart->diffInMinutes($employeeStart),
            'source' => 'generated_flex_resource_contract_split_prefix',
            'is_locked' => false,
            'metadata' => json_encode([
                'segment_kind' => 'flex_resource_contract_split_prefix',
                'reduced_contract_resource_id' => (int) $assignment->resource_id,
                'tail_resource_id' => $resourceId,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function minimumEmployeeSegmentMinutes(): int
    {
        return max(1, $this->ruleMetadataInt('spread_partial_top_ups', 'minimum_employee_segment_minutes', 0));
    }

    private function minimumContractAssignmentsPerActiveResource(): int
    {
        return max(0, $this->ruleMetadataInt('contract_usage', 'minimum_assignments_per_active_resource', 0));
    }

    private function maxFlexResourceSplitsPerDay(int $flexResourceId): int
    {
        return max(1, $this->flexResourcePolicyInt($flexResourceId, 'max_splits_per_day', 0));
    }

    private function maxFlexResourcePrefixesPerPeriod(int $flexResourceId): int
    {
        return max(0, $this->flexResourcePolicyInt($flexResourceId, 'max_prefixes_per_period', 0));
    }

    private function flexResourceAllowedShiftCodes(int $flexResourceId): array
    {
        $codes = $this->flexResourcePolicy($flexResourceId)['allowed_shift_codes'] ?? [];

        return $this->stringList($codes);
    }

    private function flexResourceSelfTopUpAllowedShiftCodes(int $flexResourceId): array
    {
        $codes = $this->flexResourcePolicy($flexResourceId)['self_top_up_allowed_shift_codes'] ?? [];

        return $this->stringList($codes);
    }

    private function flexResourceSelfTopUpAllowedUnitCodes(int $flexResourceId): array
    {
        $codes = $this->flexResourcePolicy($flexResourceId)['self_top_up_allowed_unit_codes'] ?? [];

        return $this->stringList($codes);
    }

    private function stringList(mixed $codes): array
    {
        $codes = is_array($codes) ? $codes : [$codes];

        return array_values(array_filter(array_map('strval', $codes)));
    }

    private function flexResourcePolicyInt(int $flexResourceId, string $key, int $fallback): int
    {
        return (int) ($this->flexResourcePolicy($flexResourceId)[$key] ?? $fallback);
    }

    private function flexResourcePolicy(int $flexResourceId): array
    {
        $policy = $this->ruleMetadata('flex_resource_one_split_per_day');
        if (! Schema::hasTable('resource_substitution_policies')) {
            return $policy;
        }

        foreach (DB::table('resource_substitution_policies')->where('resource_id', $flexResourceId)->get(['metadata']) as $row) {
            $metadata = json_decode($row->metadata ?? '[]', true) ?: [];
            $policy = array_replace($policy, $metadata);
        }

        return $policy;
    }

    private function ruleMetadataInt(string $code, string $key, int $fallback): int
    {
        return (int) ($this->ruleMetadata($code)[$key] ?? $fallback);
    }

    private function ruleMetadata(string $code): array
    {
        $rule = collect(config('planning.rules', []))->firstWhere('code', $code) ?? [];
        $metadata = $rule['metadata'] ?? [];

        if (! Schema::hasTable('planning_rule_settings')) {
            return $metadata;
        }

        $row = DB::table('planning_rule_settings')->where('code', $code)->first(['metadata']);
        if ($row === null) {
            return $metadata;
        }

        return array_replace($metadata, json_decode($row->metadata ?? '[]', true) ?: []);
    }

    private function ensureContractMinimumAssignments(int $planningRunId, int $planningPeriodId): void
    {
        foreach ($this->contractResourceIdsWithoutAssignments($planningRunId) as $resourceId) {
            foreach ($this->contractAssignmentsAvailableForRebalance($planningRunId) as $assignment) {
                if ($this->rebalanceContractAssignmentTo($planningRunId, $planningPeriodId, $assignment, $resourceId, 'contract_minimum_rebalanced')) {
                    break;
                }
            }
        }

        $this->balanceContractAssignments($planningRunId, $planningPeriodId);
    }

    private function balanceContractAssignments(int $planningRunId, int $planningPeriodId): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $counts = $this->contractAssignmentCounts($planningRunId);
            if ($counts === []) {
                return;
            }

            $recipients = $counts;
            asort($recipients);
            $donors = $counts;
            arsort($donors);

            if ((int) max($counts) <= (int) min($counts) + 1) {
                return;
            }

            $changed = false;
            foreach ($recipients as $recipientId => $recipientCount) {
                foreach ($donors as $donorId => $donorCount) {
                    if ($donorCount <= $recipientCount + 1) {
                        continue;
                    }

                    foreach ($this->contractAssignmentsAvailableForRebalance($planningRunId, (int) $donorId) as $assignment) {
                        if ($this->rebalanceContractAssignmentTo($planningRunId, $planningPeriodId, $assignment, (int) $recipientId, 'contract_evenly_rebalanced')) {
                            $changed = true;
                            break 3;
                        }
                    }
                }
            }

            if (! $changed) {
                return;
            }
        }
    }

    private function rebalanceContractAssignmentTo(int $planningRunId, int $planningPeriodId, object $assignment, int $resourceId, string $segmentKind): bool
    {
        if ((int) $assignment->resource_id === $resourceId) {
            return false;
        }
        if ($this->contractAssignmentCount($planningRunId, (int) $assignment->resource_id) <= $this->minimumContractAssignmentsPerActiveResource()) {
            return false;
        }

        $resourceSkills = $this->skillIdsForResource($resourceId);
        $requiredSkills = DB::table('demand_slot_required_skill')
            ->where('demand_slot_id', $assignment->demand_slot_id)
            ->pluck('skill_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (array_diff($requiredSkills, $resourceSkills) !== []) {
            return false;
        }
        if (! $this->seniorCoverageStaysCoveredAfterResourceChange($planningRunId, $assignment, $resourceSkills)) {
            return false;
        }

        $startsAt = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
        $endsAt = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
        if ($this->hasAssignmentConflict($planningRunId, $planningPeriodId, $resourceId, $startsAt, $endsAt)) {
            return false;
        }

        DB::table('assignments')->where('id', $assignment->id)->update([
            'resource_id' => $resourceId,
            'source' => 'generated_'.$segmentKind,
            'metadata' => json_encode([
                'segment_kind' => $segmentKind,
                'replaced_contract_resource_id' => (int) $assignment->resource_id,
            ]),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function contractAssignmentCounts(int $planningRunId): array
    {
        $counts = DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->whereNotNull('resource_id')
            ->groupBy('resource_id')
            ->selectRaw('resource_id, count(*) as assignments_count')
            ->pluck('assignments_count', 'resource_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return $this->contractResourceIds()
            ->mapWithKeys(fn (int $resourceId): array => [$resourceId => $counts[$resourceId] ?? 0])
            ->all();
    }

    private function contractResourceIdsWithoutAssignments(int $planningRunId): array
    {
        $counts = DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->whereNotNull('resource_id')
            ->groupBy('resource_id')
            ->selectRaw('resource_id, count(*) as assignments_count')
            ->pluck('assignments_count', 'resource_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return $this->contractResourceIds()
            ->filter(fn (int $resourceId): bool => ($counts[$resourceId] ?? 0) === 0)
            ->values()
            ->all();
    }

    private function contractAssignmentsAvailableForRebalance(int $planningRunId, ?int $resourceId = null): array
    {
        $counts = DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->whereNotNull('resource_id')
            ->groupBy('resource_id')
            ->selectRaw('resource_id, count(*) as assignments_count')
            ->pluck('assignments_count', 'resource_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('resources', 'resources.id', '=', 'assignments.resource_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->whereNotNull('assignments.resource_id')
            ->when($resourceId !== null, fn ($query) => $query->where('assignments.resource_id', $resourceId))
            ->get([
                'assignments.*',
                'resources.metadata as resource_metadata',
                'demand_slots.starts_at as slot_starts_at',
                'demand_slots.ends_at as slot_ends_at',
                'demand_slots.metadata as slot_metadata',
            ])
            ->filter(function ($assignment) use ($counts): bool {
                $metadata = json_decode($assignment->resource_metadata ?? '[]', true) ?: [];

                return ($metadata['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage'
                    && ($counts[(int) $assignment->resource_id] ?? 0) > $this->minimumContractAssignmentsPerActiveResource();
            })
            ->sortByDesc(fn ($assignment): int => $counts[(int) $assignment->resource_id] ?? 0)
            ->values()
            ->all();
    }

    private function contractResourceIds(): Collection
    {
        return DB::table('resources')
            ->where('is_active', true)
            ->get(['id', 'metadata'])
            ->filter(function ($resource): bool {
                $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];

                return ($metadata['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage';
            })
            ->map(fn ($resource): int => (int) $resource->id)
            ->values();
    }

    private function flexResourcePrefixMinutes(int $flexResourceId): int
    {
        return max(1, $this->flexResourcePolicyInt($flexResourceId, 'prefix_minutes', 0));
    }

    private function reduceContractUsageWithFlexResourcePrefixes(int $planningRunId, int $planningPeriodId): void
    {
        $flexResourceId = $this->flexResourceId();
        if ($flexResourceId === null) {
            return;
        }

        $maxPrefixes = $this->maxFlexResourcePrefixesPerPeriod($flexResourceId);
        $prefixes = 0;

        foreach ($this->assignmentsForFlexResourcePrefix($planningRunId, $flexResourceId) as $assignment) {
            if ($prefixes >= $maxPrefixes) {
                break;
            }

            $prefixMinutes = min($this->flexResourcePrefixMinutes($flexResourceId), ((int) $assignment->duration_minutes) - $this->minimumEmployeeSegmentMinutes());
            if ($prefixMinutes <= 0) {
                continue;
            }

            $startsAt = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $prefixEndsAt = $startsAt->addMinutes($prefixMinutes);
            $endsAt = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
            if ($prefixEndsAt >= $endsAt || $prefixEndsAt->diffInMinutes($endsAt) < $this->minimumEmployeeSegmentMinutes()) {
                continue;
            }
            if ($this->flexResourceSplitCountForDay($planningRunId, $flexResourceId, $startsAt->toDateString()) >= $this->maxFlexResourceSplitsPerDay($flexResourceId)) {
                continue;
            }
            if (! $this->flexResourceCanCoverPrefix($planningRunId, $planningPeriodId, $flexResourceId, $startsAt, $prefixEndsAt)) {
                continue;
            }

            $this->reduceFlexResourcePrimaryConflicts($planningRunId, $planningPeriodId, $flexResourceId, $startsAt, $prefixEndsAt);

            $nextSegment = ((int) DB::table('assignments')
                ->where('planning_run_id', $planningRunId)
                ->where('demand_slot_id', $assignment->demand_slot_id)
                ->where('slot_position', $assignment->slot_position)
                ->max('segment_position')) + 1;

            DB::table('assignments')->where('id', $assignment->id)->update([
                'starts_at' => $prefixEndsAt->toDateTimeString(),
                'duration_minutes' => $prefixEndsAt->diffInMinutes($endsAt),
                'source' => 'generated_reduced_by_flex_resource_prefix',
                'metadata' => json_encode([
                    'segment_kind' => 'tail_after_flex_resource_prefix',
                    'flex_resource_id' => $flexResourceId,
                ]),
                'updated_at' => now(),
            ]);

            DB::table('assignments')->insert([
                'planning_period_id' => $planningPeriodId,
                'demand_slot_id' => $assignment->demand_slot_id,
                'slot_position' => $assignment->slot_position,
                'segment_position' => $nextSegment,
                'resource_id' => $flexResourceId,
                'planning_run_id' => $planningRunId,
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $prefixEndsAt->toDateTimeString(),
                'duration_minutes' => $prefixMinutes,
                'source' => 'generated_flex_resource_prefix',
                'is_locked' => false,
                'metadata' => json_encode([
                    'segment_kind' => 'flex_resource_prefix',
                    'reduced_resource_id' => (int) $assignment->resource_id,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $prefixes++;
        }
    }

    private function assignmentsForFlexResourcePrefix(int $planningRunId, int $flexResourceId): array
    {
        $flexResourceSkills = $this->skillIdsForResource($flexResourceId);
        $primaryUnitIds = $this->flexResourcePrimaryUnitIds($flexResourceId);

        return DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->join('resources', 'resources.id', '=', 'assignments.resource_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.duration_minutes', '>', $this->minimumEmployeeSegmentMinutes())
            ->whereIn('shift_templates.code', $this->flexResourceAllowedShiftCodes($flexResourceId))
            ->when($primaryUnitIds !== [], fn ($query) => $query->whereNotIn('planning_units.id', $primaryUnitIds))
            ->get([
                'assignments.*',
                'resources.metadata as resource_metadata',
                'planning_units.code as unit_code',
                'demand_slots.starts_at as slot_starts_at',
                'demand_slots.ends_at as slot_ends_at',
                'demand_slots.metadata as slot_metadata',
            ])
            ->filter(function ($assignment) use ($planningRunId, $flexResourceId, $flexResourceSkills): bool {
                if ((int) $assignment->resource_id === $flexResourceId) {
                    return false;
                }

                if (! $this->resourceHasDemandSlotSkills($flexResourceSkills, (int) $assignment->demand_slot_id)) {
                    return false;
                }

                return $this->seniorCoverageStaysCoveredAfterPrefix($planningRunId, $assignment, $flexResourceSkills);
            })
            ->sortByDesc(function ($assignment) use ($planningRunId): int {
                $metadata = json_decode($assignment->resource_metadata ?? '[]', true) ?: [];
                $contractPriority = ($metadata['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage' ? 1_000_000 : 0;

                return $contractPriority + $this->contractOverPreferredMinutesForResource((int) $assignment->resource_id, $planningRunId);
            })
            ->values()
            ->all();
    }

    private function resourceHasDemandSlotSkills(array $resourceSkills, int $demandSlotId): bool
    {
        $requiredSkills = DB::table('demand_slot_required_skill')
            ->where('demand_slot_id', $demandSlotId)
            ->pluck('skill_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_diff($requiredSkills, $resourceSkills) === [];
    }

    private function seniorCoverageStaysCoveredAfterPrefix(int $planningRunId, object $assignment, array $flexResourceSkills): bool
    {
        return $this->seniorCoverageStaysCoveredAfterResourceChange($planningRunId, $assignment, $flexResourceSkills);
    }

    private function seniorCoverageStaysCoveredAfterResourceChange(int $planningRunId, object $assignment, array $replacementSkills): bool
    {
        $slotMetadata = json_decode($assignment->slot_metadata ?? '[]', true) ?: [];
        $groupKey = $slotMetadata['senior_coverage_group'] ?? null;
        if ($groupKey === null) {
            return true;
        }

        $seniorSkillIds = array_map('intval', $slotMetadata['senior_skill_ids'] ?? []);
        $currentResourceSkills = $this->skillIdsForResource((int) $assignment->resource_id);
        if (array_intersect($seniorSkillIds, $currentResourceSkills) === []) {
            return true;
        }
        if (array_intersect($seniorSkillIds, $replacementSkills) !== []) {
            return true;
        }

        foreach ($this->assignmentsInSeniorCoverageGroup($planningRunId, $groupKey) as $groupAssignment) {
            if ((int) $groupAssignment->id === (int) $assignment->id) {
                continue;
            }

            $skills = $this->skillIdsForResource((int) $groupAssignment->resource_id);
            if (array_intersect($seniorSkillIds, $skills) !== []) {
                return true;
            }
        }

        return false;
    }

    private function assignmentsInSeniorCoverageGroup(int $planningRunId, string $groupKey): array
    {
        return DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->whereNotNull('assignments.resource_id')
            ->whereRaw("json_unquote(json_extract(demand_slots.metadata, '$.senior_coverage_group')) = ?", [$groupKey])
            ->get(['assignments.id', 'assignments.resource_id'])
            ->all();
    }

    private function contractPreferredMaxMinutes(): int
    {
        return DB::table('resources')
            ->where('is_active', true)
            ->get(['metadata'])
            ->sum(function ($resource): int {
                $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];
                if (($metadata['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage') {
                    return 0;
                }

                return (int) ($metadata['preferred_max_minutes'] ?? 0);
            });
    }

    private function contractAssignmentCount(int $planningRunId, int $resourceId): int
    {
        return DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('resource_id', $resourceId)
            ->count();
    }

    private function plannedContractMinutes(int $planningRunId): int
    {
        return DB::table('assignments')
            ->join('resources', 'resources.id', '=', 'assignments.resource_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->get(['assignments.duration_minutes', 'resources.metadata'])
            ->sum(function ($assignment): int {
                $metadata = json_decode($assignment->metadata ?? '[]', true) ?: [];
                if (($metadata['workload_policy'] ?? 'must_fill_nominal') !== 'minimize_usage') {
                    return 0;
                }

                return (int) $assignment->duration_minutes;
            });
    }

    private function contractOverPreferredMinutesForResource(int $resourceId, int $planningRunId): int
    {
        $metadata = json_decode(DB::table('resources')->where('id', $resourceId)->value('metadata') ?? '[]', true) ?: [];
        $preferredMax = (int) ($metadata['preferred_max_minutes'] ?? 0);
        if ($preferredMax <= 0) {
            return 0;
        }

        $planned = (int) DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('resource_id', $resourceId)
            ->sum('duration_minutes');

        return max(0, $planned - $preferredMax);
    }

    private function demandSlotHasNominalSplit(int $planningRunId, int $demandSlotId): bool
    {
        return DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('demand_slot_id', $demandSlotId)
            ->where(function ($query): void {
                $query->where('metadata', 'like', '%"segment_kind":"contract_prefix_reduced"%')
                    ->orWhere('metadata', 'like', '%"segment_kind":"flex_resource_prefix"%')
                    ->orWhere('metadata', 'like', '%"segment_kind":"flex_resource_contract_split_prefix"%')
                    ->orWhere('metadata', 'like', '%"segment_kind":"contract_split_employee_tail"%');
            })
            ->exists();
    }

    private function flexResourceCanCoverPrefix(int $planningRunId, int $planningPeriodId, int $flexResourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $primaryUnitIds = $this->flexResourcePrimaryUnitIds($flexResourceId);
        if ($this->windowBlockedForResource($flexResourceId, $startsAt, $endsAt)) {
            return false;
        }

        foreach (DB::table('absences')->where('resource_id', $flexResourceId)->where('blocks_planning', true)->get(['starts_at', 'ends_at']) as $absence) {
            if ($startsAt < CarbonImmutable::parse($absence->ends_at) && CarbonImmutable::parse($absence->starts_at) < $endsAt) {
                return false;
            }
        }

        foreach (DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $flexResourceId)
            ->when($primaryUnitIds !== [], fn ($query) => $query->whereNotIn('planning_units.id', $primaryUnitIds))
            ->get(['assignments.starts_at', 'assignments.ends_at', 'demand_slots.starts_at as slot_starts_at', 'demand_slots.ends_at as slot_ends_at']) as $assignment) {
            $assignmentStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $assignmentEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
            if ($startsAt < $assignmentEnd && $assignmentStart < $endsAt) {
                return false;
            }
        }

        $duration = $startsAt->diffInMinutes($endsAt);
        $releasedMinutes = $this->flexResourcePrimaryOverlapMinutes($planningRunId, $flexResourceId, $startsAt, $endsAt);
        $netMinutes = max(0, $duration - $releasedMinutes);
        if ($netMinutes <= 0) {
            return true;
        }

        $maxDay = (int) (DB::table('resource_planning_limits')->where('resource_id', $flexResourceId)->where('planning_period_id', $planningPeriodId)->value('max_minutes_per_day') ?? 0);
        if ($maxDay > 0 && $this->plannedMinutesForDay($planningRunId, $flexResourceId, $startsAt->toDateString()) - $releasedMinutes + $duration > $maxDay) {
            return false;
        }

        $limit = DB::table('resource_planning_limits')->where('resource_id', $flexResourceId)->where('planning_period_id', $planningPeriodId)->first(['target_minutes_per_month', 'max_minutes_per_month']);
        $monthlyLimit = (int) ($limit?->max_minutes_per_month ?? $limit?->target_minutes_per_month ?? 0);
        if ($monthlyLimit > 0) {
            $planned = $this->plannedWorkMinutes($planningRunId)[$flexResourceId] ?? 0;
            $absences = $this->paidAbsenceMinutes($planningPeriodId)[$flexResourceId] ?? 0;
            if ($planned - $releasedMinutes + $duration + $absences > $monthlyLimit) {
                return false;
            }
        }

        return true;
    }

    private function flexResourcePrimaryOverlapMinutes(int $planningRunId, int $flexResourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): int
    {
        $minutes = 0;
        $primaryUnitIds = $this->flexResourcePrimaryUnitIds($flexResourceId);
        if ($primaryUnitIds === []) {
            return 0;
        }

        foreach (DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $flexResourceId)
            ->when($primaryUnitIds !== [], fn ($query) => $query->whereIn('planning_units.id', $primaryUnitIds))
            ->get(['assignments.starts_at', 'assignments.ends_at', 'demand_slots.starts_at as slot_starts_at', 'demand_slots.ends_at as slot_ends_at']) as $assignment) {
            $assignmentStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $assignmentEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
            $overlapStart = $startsAt > $assignmentStart ? $startsAt : $assignmentStart;
            $overlapEnd = $endsAt < $assignmentEnd ? $endsAt : $assignmentEnd;
            if ($overlapStart < $overlapEnd) {
                $minutes += $overlapStart->diffInMinutes($overlapEnd);
            }
        }

        return $minutes;
    }

    private function addSupplementaryNominalTopUp(int $planningRunId, int $planningPeriodId, int $resourceId, int $missing, array $allowedShiftCodes = [], array $allowedUnitCodes = []): bool
    {
        $primaryUnitIds = $this->allFlexResourcePrimaryUnitIds();
        $slots = DB::table('demand_slots')
            ->leftJoin('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->where('demand_slots.planning_period_id', $planningPeriodId)
            ->where('demand_slots.duration_minutes', '>=', $missing)
            ->when($primaryUnitIds !== [], fn ($query) => $query->whereNotIn('planning_units.id', $primaryUnitIds))
            ->orderBy('demand_slots.starts_at')
            ->get(['demand_slots.*', 'shift_templates.code as shift_code', 'planning_units.code as unit_code']);

        $candidates = [];
        foreach ($slots as $slot) {
            if ($allowedShiftCodes !== [] && ! in_array($slot->shift_code, $allowedShiftCodes, true)) {
                continue;
            }
            if ($allowedUnitCodes !== [] && ! in_array($slot->unit_code, $allowedUnitCodes, true)) {
                continue;
            }
            if ($this->slotBlockedForResource($resourceId, $slot)) {
                continue;
            }

            $slotStart = CarbonImmutable::parse($slot->starts_at);
            $slotEnd = CarbonImmutable::parse($slot->ends_at);
            $duration = $this->supplementaryDurationForSlot($planningRunId, $planningPeriodId, $resourceId, $slot, $missing);
            if ($duration <= 0) {
                continue;
            }
            $placements = [
                'tail' => [$slotEnd->subMinutes($duration), $slotEnd],
                'prefix' => [$slotStart, $slotStart->addMinutes($duration)],
            ];
            foreach ($this->adjacentPlacementsForResource($planningRunId, $resourceId, $slot, $duration) as $placement => $window) {
                $placements[$placement] = $window;
            }

            foreach ($placements as $placement => [$segmentStart, $segmentEnd]) {
                if ($segmentStart < $slotStart || $segmentEnd > $slotEnd) {
                    continue;
                }
                if ($this->hasAssignmentConflict($planningRunId, $planningPeriodId, $resourceId, $segmentStart, $segmentEnd)) {
                    continue;
                }

                $candidates[] = [
                    'score' => $this->supplementaryTopUpCandidateScore($planningRunId, $planningPeriodId, $resourceId, $segmentStart),
                    'slot' => $slot,
                    'placement' => $placement,
                    'starts_at' => $segmentStart,
                    'ends_at' => $segmentEnd,
                    'duration_minutes' => $duration,
                ];
            }
        }

        if ($candidates === []) {
            return false;
        }

        usort($candidates, fn (array $a, array $b): int => [$a['score'], $a['starts_at']->toDateTimeString()] <=> [$b['score'], $b['starts_at']->toDateTimeString()]);
        $candidate = $candidates[0];
        $slot = $candidate['slot'];
        $nextPosition = ((int) DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('demand_slot_id', $slot->id)
            ->max('slot_position')) + 1;

        DB::table('assignments')->insert([
            'planning_period_id' => $planningPeriodId,
            'demand_slot_id' => $slot->id,
            'slot_position' => $nextPosition,
            'segment_position' => 1,
            'resource_id' => $resourceId,
            'planning_run_id' => $planningRunId,
            'starts_at' => $candidate['starts_at']->toDateTimeString(),
            'ends_at' => $candidate['ends_at']->toDateTimeString(),
            'duration_minutes' => $candidate['duration_minutes'],
            'source' => 'generated_supplementary_nominal_top_up',
            'is_locked' => false,
            'metadata' => json_encode(['segment_kind' => 'supplementary_nominal_top_up', 'covers_demand' => false, 'placement' => $candidate['placement']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function maxDemandSlotMinutes(int $planningPeriodId): int
    {
        return (int) DB::table('demand_slots')
            ->where('planning_period_id', $planningPeriodId)
            ->max('duration_minutes');
    }

    private function supplementaryDurationForSlot(int $planningRunId, int $planningPeriodId, int $resourceId, object $slot, int $missing): int
    {
        $duration = min($missing, max(0, ((int) $slot->duration_minutes) - $this->minimumEmployeeSegmentMinutes()));
        $maxDay = (int) (DB::table('resource_planning_limits')
            ->where('resource_id', $resourceId)
            ->where('planning_period_id', $planningPeriodId)
            ->value('max_minutes_per_day') ?? 0);

        if ($maxDay <= 0) {
            return $duration;
        }

        $plannedToday = $this->plannedMinutesForDay($planningRunId, $resourceId, CarbonImmutable::parse($slot->starts_at)->toDateString());

        return max(0, min($duration, $maxDay - $plannedToday));
    }

    private function adjacentPlacementsForResource(int $planningRunId, int $resourceId, object $slot, int $missing): array
    {
        $slotStart = CarbonImmutable::parse($slot->starts_at);
        $slotEnd = CarbonImmutable::parse($slot->ends_at);
        $placements = [];

        foreach (DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $resourceId)
            ->get([
                'assignments.starts_at',
                'assignments.ends_at',
                'demand_slots.starts_at as slot_starts_at',
                'demand_slots.ends_at as slot_ends_at',
            ]) as $assignment) {
            $existingStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $existingEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);

            if ($existingEnd >= $slotStart && $existingEnd < $slotEnd) {
                $placements['after_existing_'.$assignment->slot_starts_at] = [$existingEnd, $existingEnd->addMinutes($missing)];
            }
            if ($existingStart > $slotStart && $existingStart <= $slotEnd) {
                $placements['before_existing_'.$assignment->slot_starts_at] = [$existingStart->subMinutes($missing), $existingStart];
            }
        }

        return $placements;
    }

    private function contractReassignmentCandidateScore(int $planningRunId, int $planningPeriodId, int $resourceId, object $assignment): int
    {
        $startsAt = CarbonImmutable::parse($assignment->slot_starts_at);
        $day = $startsAt->toDateString();

        $spreadWeight = (int) config('planning.weights.spread_partial_top_ups', 5000);
        $repeatWeight = (int) config('planning.weights.avoid_same_resource_streaks', 80000);
        $dayNightWeight = (int) config('planning.weights.even_nights', 3000);

        return $this->topUpSpreadPenalty($planningRunId, $planningPeriodId, $startsAt, 'contract_reassigned_full') * max(1, $spreadWeight)
            + $this->topUpCountForDay($planningRunId, $day) * $spreadWeight * 20
            + $this->resourceAssignmentsAroundDay($planningRunId, $resourceId, $day) * max(1, intdiv($repeatWeight, 4))
            + $this->dayNightBalancePenaltyAfterAssignment($planningRunId, $resourceId, (string) $assignment->shift_code) * max(1, $dayNightWeight);
    }

    private function dayNightBalancePenaltyAfterAssignment(int $planningRunId, int $resourceId, string $shiftCode): int
    {
        if (! in_array($shiftCode, ['DAY_12H', 'NIGHT_12H'], true)) {
            return 0;
        }

        $counts = DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $resourceId)
            ->whereIn('shift_templates.code', ['DAY_12H', 'NIGHT_12H'])
            ->groupBy('shift_templates.code')
            ->selectRaw('shift_templates.code as shift_code, count(*) as assignments_count')
            ->pluck('assignments_count', 'shift_code')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $total = array_sum($counts) + 1;
        if ($total < 3) {
            return 0;
        }

        $nights = ($counts['NIGHT_12H'] ?? 0) + ($shiftCode === 'NIGHT_12H' ? 1 : 0);
        $nightPercent = (int) round(($nights / $total) * 100);
        $overPreferred = max(0, $nightPercent - 60);

        return ($overPreferred ** 2) * 25;
    }

    private function supplementaryTopUpCandidateScore(int $planningRunId, int $planningPeriodId, int $resourceId, CarbonImmutable $startsAt): int
    {
        $day = $startsAt->toDateString();
        $spreadWeight = (int) config('planning.weights.spread_partial_top_ups', 5000);
        $repeatWeight = (int) config('planning.weights.avoid_same_resource_streaks', 80000);

        return $this->topUpSpreadPenalty($planningRunId, $planningPeriodId, $startsAt, 'supplementary_nominal_top_up') * max(1, $spreadWeight)
            + $this->topUpCountForDay($planningRunId, $day) * $spreadWeight * 20
            + $this->resourceAssignmentsAroundDay($planningRunId, $resourceId, $day) * max(1, intdiv($repeatWeight, 4));
    }

    private function topUpSpreadPenalty(int $planningRunId, int $planningPeriodId, CarbonImmutable $startsAt, string $segmentKind): int
    {
        $period = DB::table('planning_periods')->where('id', $planningPeriodId)->first(['starts_on', 'ends_on']);
        $middleDay = $period
            ? intdiv(CarbonImmutable::parse($period->starts_on)->day + CarbonImmutable::parse($period->ends_on)->day, 2)
            : 16;
        $candidateDay = $startsAt->day;
        $existingDays = DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('metadata', 'like', '%"segment_kind":"'.$segmentKind.'"%')
            ->pluck('starts_at')
            ->map(fn ($value): int => CarbonImmutable::parse($value)->day)
            ->all();

        if ($existingDays === []) {
            return abs($candidateDay - $middleDay);
        }

        $nearestDistance = min(array_map(fn (int $day): int => abs($candidateDay - $day), $existingDays));

        return -($nearestDistance * 20) + abs($candidateDay - $middleDay);
    }

    private function topUpCountForDay(int $planningRunId, string $day): int
    {
        return DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->whereIn('source', ['generated_partial_nominal_top_up', 'generated_supplementary_nominal_top_up'])
            ->whereRaw('date(starts_at) = ?', [$day])
            ->count();
    }

    private function flexResourceSplitCountForDay(int $planningRunId, int $flexResourceId, string $day): int
    {
        return DB::table('assignments')
            ->where('planning_run_id', $planningRunId)
            ->where('resource_id', $flexResourceId)
            ->where(function ($query): void {
                $query->where('metadata', 'like', '%"segment_kind":"flex_resource_prefix"%')
                    ->orWhere('metadata', 'like', '%"segment_kind":"flex_resource_contract_split_prefix"%');
            })
            ->whereRaw('date(starts_at) = ?', [$day])
            ->count();
    }

    private function resourceAssignmentsAroundDay(int $planningRunId, int $resourceId, string $day): int
    {
        $startsOn = CarbonImmutable::parse($day)->subDays(2)->toDateString();
        $endsOn = CarbonImmutable::parse($day)->addDays(2)->toDateString();

        return DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $resourceId)
            ->whereRaw('date(coalesce(assignments.starts_at, demand_slots.starts_at)) between ? and ?', [$startsOn, $endsOn])
            ->count();
    }

    private function reduceFlexResourcePrimaryConflicts(int $planningRunId, int $planningPeriodId, int $flexResourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $primaryUnitIds = $this->flexResourcePrimaryUnitIds($flexResourceId);
        if ($primaryUnitIds === []) {
            return;
        }

        foreach (DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $flexResourceId)
            ->whereIn('planning_units.id', $primaryUnitIds)
            ->get(['assignments.*', 'demand_slots.starts_at as slot_starts_at', 'demand_slots.ends_at as slot_ends_at']) as $assignment) {
            $assignmentStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $assignmentEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);

            if (! ($startsAt < $assignmentEnd && $assignmentStart < $endsAt)) {
                continue;
            }

            $segments = [];
            if ($assignmentStart < $startsAt) {
                $segments[] = [$assignmentStart, $startsAt < $assignmentEnd ? $startsAt : $assignmentEnd];
            }
            if ($endsAt < $assignmentEnd) {
                $segments[] = [$endsAt > $assignmentStart ? $endsAt : $assignmentStart, $assignmentEnd];
            }

            $segments = array_values(array_filter(
                $segments,
                fn (array $segment): bool => $segment[0]->diffInMinutes($segment[1], false) > 0,
            ));

            if ($segments === []) {
                DB::table('assignments')->where('id', $assignment->id)->delete();

                continue;
            }

            $metadata = json_decode($assignment->metadata ?? '[]', true) ?: [];
            $baseMetadata = array_merge($metadata, [
                'segment_kind' => 'flex_resource_primary_reduced',
                'reduced_by_window' => [
                    'starts_at' => $startsAt->toDateTimeString(),
                    'ends_at' => $endsAt->toDateTimeString(),
                ],
            ]);
            $firstSegment = array_shift($segments);

            DB::table('assignments')->where('id', $assignment->id)->update([
                'starts_at' => $firstSegment[0]->toDateTimeString(),
                'ends_at' => $firstSegment[1]->toDateTimeString(),
                'duration_minutes' => $firstSegment[0]->diffInMinutes($firstSegment[1]),
                'source' => 'generated_flex_resource_reduced',
                'metadata' => json_encode($baseMetadata),
                'updated_at' => now(),
            ]);

            $nextSegment = ((int) DB::table('assignments')
                ->where('planning_run_id', $planningRunId)
                ->where('demand_slot_id', $assignment->demand_slot_id)
                ->where('slot_position', $assignment->slot_position)
                ->max('segment_position')) + 1;

            foreach ($segments as $segment) {
                DB::table('assignments')->insert([
                    'planning_period_id' => $planningPeriodId,
                    'demand_slot_id' => $assignment->demand_slot_id,
                    'slot_position' => $assignment->slot_position,
                    'segment_position' => $nextSegment++,
                    'resource_id' => $flexResourceId,
                    'planning_run_id' => $planningRunId,
                    'starts_at' => $segment[0]->toDateTimeString(),
                    'ends_at' => $segment[1]->toDateTimeString(),
                    'duration_minutes' => $segment[0]->diffInMinutes($segment[1]),
                    'source' => 'generated_flex_resource_reduced',
                    'is_locked' => false,
                    'metadata' => json_encode($baseMetadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function underfilledEmploymentResources(int $planningRunId, int $planningPeriodId): array
    {
        $planned = $this->plannedWorkMinutes($planningRunId);
        $absences = $this->paidAbsenceMinutes($planningPeriodId);
        $fullDutyMinutes = $this->maxDemandSlotMinutes($planningPeriodId);

        return DB::table('resources')
            ->join('resource_planning_limits', 'resource_planning_limits.resource_id', '=', 'resources.id')
            ->where('resource_planning_limits.planning_period_id', $planningPeriodId)
            ->whereNotNull('resource_planning_limits.target_minutes_per_month')
            ->get(['resources.id', 'resources.metadata', 'resource_planning_limits.target_minutes_per_month'])
            ->map(function ($resource) use ($planned, $absences): array {
                $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];
                if (($metadata['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
                    return ['resource_id' => (int) $resource->id, 'missing_minutes' => 0];
                }

                $target = (int) $resource->target_minutes_per_month;
                $total = ($planned[$resource->id] ?? 0) + ($absences[$resource->id] ?? 0);

                return ['resource_id' => (int) $resource->id, 'missing_minutes' => max(0, $target - $total)];
            })
            ->filter(fn (array $row): bool => $row['missing_minutes'] > 0)
            ->sort(function (array $a, array $b) use ($fullDutyMinutes): int {
                return [
                    $this->nominalRepairPriority($a['missing_minutes'], $fullDutyMinutes),
                    $a['missing_minutes'],
                ] <=> [
                    $this->nominalRepairPriority($b['missing_minutes'], $fullDutyMinutes),
                    $b['missing_minutes'],
                ];
            })
            ->values()
            ->all();
    }

    private function nominalRepairPriority(int $missingMinutes, int $fullDutyMinutes): int
    {
        if ($missingMinutes >= $fullDutyMinutes && $missingMinutes < $fullDutyMinutes * 2) {
            return 0;
        }

        if ($missingMinutes >= $fullDutyMinutes * 2) {
            return 1;
        }

        return 2;
    }

    private function nominalUnderfillViolations(int $planningRunId, int $planningPeriodId): array
    {
        return array_map(function (array $row) use ($planningRunId, $planningPeriodId): array {
            $planned = $this->plannedWorkMinutes($planningRunId);
            $absences = $this->paidAbsenceMinutes($planningPeriodId);
            $limit = DB::table('resource_planning_limits')->where('resource_id', $row['resource_id'])->where('planning_period_id', $planningPeriodId)->first();
            $missing = (int) $row['missing_minutes'];

            return [
                'code' => 'nominal_carryover',
                'severity' => 'soft',
                'message' => 'Niedobór miesięcznego nominału do przeniesienia na rozliczenie kwartalne.',
                'resource_id' => $row['resource_id'],
                'missing_minutes' => $missing,
                'planned_work_minutes' => $planned[$row['resource_id']] ?? 0,
                'paid_absence_minutes' => $absences[$row['resource_id']] ?? 0,
                'target_minutes' => (int) ($limit?->target_minutes_per_month ?? 0),
                'carryover_to_quarter' => true,
            ];
        }, $this->underfilledEmploymentResources($planningRunId, $planningPeriodId));
    }

    private function plannedWorkMinutes(int $planningRunId): array
    {
        return DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->whereNotNull('assignments.resource_id')
            ->selectRaw('assignments.resource_id, sum(coalesce(assignments.duration_minutes, demand_slots.duration_minutes)) as minutes')
            ->groupBy('assignments.resource_id')
            ->pluck('minutes', 'assignments.resource_id')
            ->map(fn ($minutes): int => (int) $minutes)
            ->all();
    }

    private function paidAbsenceMinutes(int $planningPeriodId): array
    {
        $period = DB::table('planning_periods')->where('id', $planningPeriodId)->first();

        return DB::table('absences')
            ->where('counts_as_work_time', true)
            ->where('starts_at', '<', $period->ends_on.' 23:59:59')
            ->where('ends_at', '>', $period->starts_on.' 00:00:00')
            ->selectRaw('resource_id, sum(nominal_minutes) as minutes')
            ->groupBy('resource_id')
            ->pluck('minutes', 'resource_id')
            ->map(fn ($minutes): int => (int) $minutes)
            ->all();
    }

    private function flexResourceId(): ?int
    {
        if (Schema::hasTable('resource_substitution_policies')) {
            $resourceId = DB::table('resource_substitution_policies')
                ->orderBy('id')
                ->value('resource_id');
            if ($resourceId !== null) {
                return (int) $resourceId;
            }
        }

        foreach (DB::table('resources')->where('is_active', true)->get(['id', 'metadata']) as $resource) {
            $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];
            if (array_intersect(['flex_resource', 'ward_manager'], $metadata['roles'] ?? []) !== []) {
                return (int) $resource->id;
            }
        }

        return null;
    }

    private function flexResourcePrimaryUnitIds(int $flexResourceId): array
    {
        if (! Schema::hasTable('resource_substitution_policies')) {
            return [];
        }

        return DB::table('resource_substitution_policies')
            ->where('resource_id', $flexResourceId)
            ->pluck('primary_planning_unit_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function allFlexResourcePrimaryUnitIds(): array
    {
        if (! Schema::hasTable('resource_substitution_policies')) {
            return [];
        }

        return DB::table('resource_substitution_policies')
            ->pluck('primary_planning_unit_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function skillIdsForResource(int $resourceId): array
    {
        return DB::table('resource_skill')->where('resource_id', $resourceId)->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function hasAssignmentConflict(int $planningRunId, int $planningPeriodId, int $resourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        if ($this->windowBlockedForResource($resourceId, $startsAt, $endsAt)) {
            return true;
        }

        foreach (DB::table('absences')->where('resource_id', $resourceId)->where('blocks_planning', true)->get(['starts_at', 'ends_at']) as $absence) {
            if ($startsAt < CarbonImmutable::parse($absence->ends_at) && CarbonImmutable::parse($absence->starts_at) < $endsAt) {
                return true;
            }
        }

        $duration = $startsAt->diffInMinutes($endsAt);
        $maxDay = (int) (DB::table('resource_planning_limits')->where('resource_id', $resourceId)->where('planning_period_id', $planningPeriodId)->value('max_minutes_per_day') ?? 0);
        if ($maxDay > 0 && $this->plannedMinutesForDay($planningRunId, $resourceId, $startsAt->toDateString()) + $duration > $maxDay) {
            return true;
        }

        $minRest = (int) (DB::table('resource_planning_limits')->where('resource_id', $resourceId)->where('planning_period_id', $planningPeriodId)->value('min_rest_minutes') ?? 0);
        foreach (DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $resourceId)
            ->get(['assignments.starts_at', 'assignments.ends_at', 'demand_slots.starts_at as slot_starts_at', 'demand_slots.ends_at as slot_ends_at']) as $assignment) {
            $existingStart = CarbonImmutable::parse($assignment->starts_at ?? $assignment->slot_starts_at);
            $existingEnd = CarbonImmutable::parse($assignment->ends_at ?? $assignment->slot_ends_at);
            if ($startsAt < $existingEnd && $existingStart < $endsAt) {
                return true;
            }
            if ($existingEnd <= $startsAt && $existingEnd->diffInMinutes($startsAt, false) < $minRest) {
                return true;
            }
            if ($endsAt <= $existingStart && $endsAt->diffInMinutes($existingStart, false) < $minRest) {
                return true;
            }
        }

        return false;
    }

    private function plannedMinutesForDay(int $planningRunId, int $resourceId, string $day): int
    {
        return (int) DB::table('assignments')
            ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
            ->where('assignments.planning_run_id', $planningRunId)
            ->where('assignments.resource_id', $resourceId)
            ->whereRaw('date(coalesce(assignments.starts_at, demand_slots.starts_at)) = ?', [$day])
            ->sum(DB::raw('coalesce(assignments.duration_minutes, demand_slots.duration_minutes)'));
    }

    private function slotBlockedForResource(int $resourceId, object $slot): bool
    {
        return $this->windowBlockedForResource($resourceId, CarbonImmutable::parse($slot->starts_at), CarbonImmutable::parse($slot->ends_at));
    }

    private function windowBlockedForResource(int $resourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        foreach (DB::table('calendar_holidays')->where('blocks_planning', true)->get() as $holiday) {
            $holidayStart = CarbonImmutable::parse($holiday->holiday_date.' 00:00:00');
            $holidayEnd = $holidayStart->addDay();
            if (! ($startsAt < $holidayEnd && $holidayStart < $endsAt)) {
                continue;
            }
            if ($holiday->scope === 'global' || ($holiday->scope === 'resource' && (int) $holiday->resource_id === $resourceId)) {
                return true;
            }
            if ($holiday->scope === 'resource_group') {
                $resourceGroupId = (int) (DB::table('resources')->where('id', $resourceId)->value('resource_group_id') ?? 0);
                if ($resourceGroupId > 0 && (int) $holiday->resource_group_id === $resourceGroupId) {
                    return true;
                }
            }
        }

        $dayOfWeek = $startsAt->dayOfWeekIso;
        $day = $startsAt->toDateString();
        foreach (DB::table('availability_rules')->where('resource_id', $resourceId)->where('rule_type', 'unavailable')->get() as $rule) {
            if ($rule->effective_from !== null && $day < $rule->effective_from) {
                continue;
            }
            if ($rule->effective_to !== null && $day > $rule->effective_to) {
                continue;
            }
            if ($rule->day_of_week !== null && (int) $rule->day_of_week !== $dayOfWeek) {
                continue;
            }

            return true;
        }

        return false;
    }
}
