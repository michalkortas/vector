<?php

namespace App\Http\Controllers;

use App\Planning\Infrastructure\PlanningRuleSettings;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController extends Controller
{
    public function __invoke(): Response
    {
        $period = DB::table('planning_periods')->where('starts_on', '2026-07-01')->first()
            ?? DB::table('planning_periods')->orderBy('starts_on')->first();

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
            ->map(function ($resource) use ($resourcePlannedMinutes, $resourceAbsenceMinutes) {
                $metadata = json_decode($resource->metadata ?? '[]', true) ?: [];
                $plannedMinutes = $resourcePlannedMinutes[$resource->id] ?? 0;
                $absenceMinutes = $resourceAbsenceMinutes[$resource->id] ?? 0;
                $targetMinutes = (int) ($resource->target_minutes_per_month ?? 0);
                $resource->employment_type = $metadata['employment_type'] ?? 'employment';
                $resource->workload_policy = $metadata['workload_policy'] ?? 'must_fill_nominal';
                $resource->planned_duties_note = $this->plannedDutiesNote((string) ($metadata['planned_duties_expression_raw'] ?? ''));
                $resource->planned_work_minutes = $plannedMinutes;
                $resource->planned_absence_minutes = $absenceMinutes;
                $resource->planned_total_minutes = $plannedMinutes + $absenceMinutes;
                $resource->remaining_work_minutes = $resource->workload_policy === 'minimize_usage' ? null : max(0, $targetMinutes - $absenceMinutes - $plannedMinutes);
                unset($resource->metadata);

                return $resource;
            });

        return Inertia::render('Schedule', [
            'period' => $period,
            'latestRun' => $latestRun,
            'assignments' => $assignments,
            'scheduleRows' => $period ? DB::table('demand_slots')
                ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
                ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
                ->where('demand_slots.planning_period_id', $period->id)
                ->selectRaw('min(demand_slots.starts_at) as first_starts_at, min(shift_templates.id) as shift_order, min(planning_units.id) as unit_order, shift_templates.code as shift_code, shift_templates.name as shift_name, planning_units.code as unit_code, planning_units.name as unit_name')
                ->groupBy('shift_templates.code', 'shift_templates.name', 'planning_units.code', 'planning_units.name')
                ->orderByRaw('min(demand_slots.starts_at)')
                ->orderBy('shift_order')
                ->orderBy('unit_order')
                ->get() : [],
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

    private function plannedDutiesNote(string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '-') {
            return '-';
        }

        return collect(explode('+', $expression))
            ->map(fn (string $part): string => $this->plannedDutiesPart(trim($part)))
            ->implode(' + ');
    }

    private function plannedDutiesPart(string $part): string
    {
        if (preg_match('/^(\d+)x(\d+)(?:,(\d{1,2}))?$/', $part, $matches) === 1) {
            $count = (int) $matches[1];
            $hours = (int) $matches[2];
            $minutes = $matches[3] ?? null;
            $duration = $minutes === null
                ? $hours.'h'
                : $hours.':'.str_pad($minutes, 2, '0', STR_PAD_LEFT);

            return $count.'x'.$duration;
        }

        if (preg_match('/^(\d+),(\d{1,2})$/', $part, $matches) === 1) {
            return '1x'.((int) $matches[1]).':'.str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^\d+$/', $part) === 1) {
            return '1x'.$part.'h';
        }

        return str_replace(',', ':', $part);
    }
}
