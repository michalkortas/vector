<?php

namespace App\Http\Controllers;

use App\Planning\Infrastructure\PlanningRuleSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController extends Controller
{
    public function __invoke(): Response
    {
        $period = DB::table('planning_periods')->where('starts_on', '2026-07-01')->first()
            ?? DB::table('planning_periods')->orderBy('starts_on')->first();
        if ($period) {
            $period->metadata = json_decode($period->metadata ?? '[]', true) ?: [];
        }

        $latestRun = $period
            ? DB::table('planning_runs')->where('planning_period_id', $period->id)->orderByDesc('id')->first()
            : null;
        if ($latestRun) {
            $latestRun->metadata = json_decode($latestRun->metadata ?? '[]', true) ?: [];
            $latestRun->config = json_decode($latestRun->config ?? '[]', true) ?: [];
        }

        $assignments = [];
        if ($period && $latestRun) {
            $assignments = DB::table('assignments')
                ->leftJoin('resources', 'resources.id', '=', 'assignments.resource_id')
                ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
                ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
                ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
                ->where('assignments.planning_run_id', $latestRun->id)
                ->orderBy('demand_slots.starts_at')
                ->get([
                    'assignments.id',
                    'assignments.demand_slot_id',
                    'assignments.resource_id',
                    'assignments.slot_position',
                    'assignments.segment_position',
                    'assignments.is_locked',
                    'assignments.source',
                    'assignments.metadata',
                    'resources.employee_number',
                    'resources.name as resource_name',
                    DB::raw('coalesce(assignments.starts_at, demand_slots.starts_at) as starts_at'),
                    DB::raw('coalesce(assignments.ends_at, demand_slots.ends_at) as ends_at'),
                    DB::raw('coalesce(assignments.duration_minutes, demand_slots.duration_minutes) as duration_minutes'),
                    'demand_slots.starts_at as slot_starts_at',
                    'planning_units.code as unit_code',
                    'planning_units.name as unit_name',
                    'shift_templates.code as shift_code',
                    'shift_templates.name as shift_name',
                ])
                ->map(function ($assignment) {
                    $assignment->metadata = json_decode($assignment->metadata ?? '[]', true) ?: [];
                    $segmentKind = $assignment->metadata['segment_kind'] ?? null;
                    $assignment->display_layer = match (true) {
                        in_array($segmentKind, ['flex_resource_prefix', 'flex_resource_contract_split_prefix', 'ward_manager_prefix', 'ward_manager_contract_split_prefix'], true) => 'top_up',
                        ($assignment->metadata['covers_demand'] ?? true) === false => 'resource_only',
                        default => 'demand',
                    };

                    return $assignment;
                });
        }

        $resourcePlannedMinutes = [];
        $resourceDutyDistribution = [];
        if ($latestRun) {
            $resourcePlannedMinutes = DB::table('assignments')
                ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
                ->where('assignments.planning_run_id', $latestRun->id)
                ->whereNotNull('assignments.resource_id')
                ->selectRaw('assignments.resource_id, sum(coalesce(assignments.duration_minutes, demand_slots.duration_minutes)) as minutes')
                ->groupBy('assignments.resource_id')
                ->pluck('minutes', 'assignments.resource_id')
                ->map(fn ($minutes): int => (int) $minutes)
                ->all();
            $resourceDutyDistribution = DB::table('assignments')
                ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
                ->where('assignments.planning_run_id', $latestRun->id)
                ->whereNotNull('assignments.resource_id')
                ->selectRaw('assignments.resource_id, coalesce(assignments.duration_minutes, demand_slots.duration_minutes) as minutes, count(*) as duties_count')
                ->groupBy('assignments.resource_id', DB::raw('coalesce(assignments.duration_minutes, demand_slots.duration_minutes)'))
                ->orderByDesc('minutes')
                ->get()
                ->groupBy('resource_id')
                ->map(fn ($rows): string => $rows
                    ->map(fn ($row): string => ((int) $row->duties_count).'x'.$this->durationCompact((int) $row->minutes))
                    ->implode(', '))
                ->all();
        }

        $resourceAbsenceMinutes = $period
            ? DB::table('absences')
                ->join('absence_types', 'absence_types.id', '=', 'absences.absence_type_id')
                ->where('absences.counts_as_work_time', true)
                ->where('absences.starts_at', '<', $period->ends_on.' 23:59:59')
                ->where('absences.ends_at', '>', $period->starts_on.' 00:00:00')
                ->selectRaw('absences.resource_id, sum(absences.nominal_minutes) as minutes')
                ->groupBy('absences.resource_id')
                ->pluck('minutes', 'absences.resource_id')
                ->map(fn ($minutes): int => (int) $minutes)
                ->all()
            : [];

        $resources = DB::table('resources')
            ->leftJoin('resource_planning_limits', 'resource_planning_limits.resource_id', '=', 'resources.id')
            ->orderBy('employee_number')
            ->get(['resources.id', 'employee_number', 'name', 'is_active', 'resources.metadata', 'target_minutes_per_month', 'target_minutes_per_quarter', 'max_minutes_per_month', 'max_minutes_per_quarter'])
            ->map(function ($resource) use ($resourcePlannedMinutes, $resourceDutyDistribution, $resourceAbsenceMinutes) {
                $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];
                $plannedMinutes = $resourcePlannedMinutes[$resource->id] ?? 0;
                $absenceMinutes = $resourceAbsenceMinutes[$resource->id] ?? 0;
                $targetMinutes = (int) ($resource->target_minutes_per_month ?? 0);
                $resource->employment_type = $metadata['employment_type'] ?? 'employment';
                $resource->workload_policy = $metadata['workload_policy'] ?? 'must_fill_nominal';
                $resource->actual_duties_note = $resourceDutyDistribution[$resource->id] ?? '-';
                $resource->planned_work_minutes = $plannedMinutes;
                $resource->planned_absence_minutes = $absenceMinutes;
                $resource->planned_total_minutes = $plannedMinutes + $absenceMinutes;
                $resource->remaining_work_minutes = $resource->workload_policy === 'minimize_usage' ? null : max(0, $targetMinutes - $absenceMinutes - $plannedMinutes);
                unset($resource->metadata);

                return $resource;
            });
        $scheduleRows = $period
            ? $this->scheduleRows((int) $period->id, ($period->metadata['demo_scenario'] ?? null) === 'vehicles')
            : [];

        return Inertia::render('Schedule', [
            'period' => $period,
            'latestRun' => $latestRun,
            'assignments' => $assignments,
            'scheduleRows' => $scheduleRows,
            'resources' => $resources,
            'units' => DB::table('planning_units')->orderBy('id')->get(),
            'shifts' => DB::table('shift_templates')->orderBy('id')->get(),
            'violations' => $latestRun ? DB::table('planning_run_violations')
                ->leftJoin('resources', 'resources.id', '=', 'planning_run_violations.resource_id')
                ->where('planning_run_id', $latestRun->id)
                ->limit(80)
                ->get([
                    'planning_run_violations.id',
                    'planning_run_violations.code',
                    'planning_run_violations.severity',
                    'planning_run_violations.message',
                    'planning_run_violations.demand_slot_id',
                    'planning_run_violations.resource_id',
                    'planning_run_violations.metadata',
                    'resources.employee_number',
                    'resources.name as resource_name',
                ])
                ->map(function ($violation) {
                    $violation->metadata = json_decode($violation->metadata ?? '[]', true) ?: [];

                    return $violation;
                }) : [],
            'scoreComponents' => $latestRun ? DB::table('planning_run_score_components')->where('planning_run_id', $latestRun->id)->get() : [],
            'planningRules' => PlanningRuleSettings::all(),
            'holidays' => $period ? DB::table('calendar_holidays')
                ->whereBetween('holiday_date', [$period->starts_on, $period->ends_on])
                ->orderBy('holiday_date')
                ->get(['holiday_date', 'name', 'scope', 'resource_id', 'resource_group_id', 'blocks_planning']) : [],
            'absences' => DB::table('absences')
                ->join('resources', 'resources.id', '=', 'absences.resource_id')
                ->join('absence_types', 'absence_types.id', '=', 'absences.absence_type_id')
                ->orderBy('starts_at')
                ->get(['resources.employee_number', 'resources.name as resource_name', 'absence_types.name as type_name', 'starts_at', 'ends_at', 'absences.metadata']),
        ]);
    }

    private function scheduleRows(int $periodId, bool $vehicles): Collection
    {
        return DB::table('demand_slots')
            ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
            ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->where('demand_slots.planning_period_id', $periodId)
            ->selectRaw('min(demand_slots.starts_at) as first_starts_at, min(shift_templates.id) as shift_order, min(planning_units.id) as unit_order, min(planning_units.metadata) as unit_metadata, shift_templates.code as shift_code, shift_templates.name as shift_name, planning_units.code as unit_code, planning_units.name as unit_name')
            ->groupBy('shift_templates.code', 'shift_templates.name', 'planning_units.code', 'planning_units.name')
            ->get()
            ->map(function ($row) {
                $metadata = json_decode($row->unit_metadata ?? '[]', true) ?: [];
                $row->sector_code = $metadata['sector_code'] ?? null;
                $row->sector_name = $metadata['sector_name'] ?? null;
                $row->sector_order = $metadata['sector_order'] ?? null;
                unset($row->unit_metadata);

                return $row;
            })
            ->sort(function ($left, $right) use ($vehicles): int {
                if ($vehicles) {
                    return [
                        (int) ($left->sector_order ?? PHP_INT_MAX),
                        (int) $left->unit_order,
                        (int) $left->shift_order,
                    ] <=> [
                        (int) ($right->sector_order ?? PHP_INT_MAX),
                        (int) $right->unit_order,
                        (int) $right->shift_order,
                    ];
                }

                return [
                    (string) $left->first_starts_at,
                    (int) $left->shift_order,
                    (int) $left->unit_order,
                ] <=> [
                    (string) $right->first_starts_at,
                    (int) $right->shift_order,
                    (int) $right->unit_order,
                ];
            })
            ->values();
    }

    private function durationCompact(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? (string) $hours : $hours.':'.str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }
}
