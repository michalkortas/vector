<?php

namespace App\Planning\Infrastructure;

use App\Planning\Domain\DTO\PlanningProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentPlanningProblemFactory
{
    public function make(int $planningPeriodId): PlanningProblem
    {
        $period = (array) DB::table('planning_periods')->find($planningPeriodId);
        $resources = DB::table('resources')->where('is_active', true)->get()->keyBy('id')->map(fn ($row): array => [
            'id' => (int) $row->id,
            'employee_number' => $row->employee_number,
            'name' => $row->name,
            'resource_group_id' => $row->resource_group_id ? (int) $row->resource_group_id : null,
            'is_active' => (bool) $row->is_active,
            'metadata' => json_decode($row->metadata ?? '[]', true) ?: [],
        ])->all();

        $skillsByResource = [];
        foreach (DB::table('resource_skill')->get() as $row) {
            $skillsByResource[(int) $row->resource_id][] = (int) $row->skill_id;
        }
        foreach (DB::table('resource_group_skill')->join('resources', 'resources.resource_group_id', '=', 'resource_group_skill.resource_group_id')->get(['resources.id as resource_id', 'resource_group_skill.skill_id']) as $row) {
            $skillsByResource[(int) $row->resource_id][] = (int) $row->skill_id;
        }
        $skillsByResource = array_map(fn (array $ids): array => array_values(array_unique($ids)), $skillsByResource);

        $slots = DB::table('demand_slots')
            ->leftJoin('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
            ->where('planning_period_id', $planningPeriodId)
            ->orderBy('starts_at')
            ->get(['demand_slots.*', 'shift_templates.code as shift_code'])
            ->keyBy('id')
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'planning_unit_id' => (int) $row->planning_unit_id,
                'shift_template_id' => $row->shift_template_id ? (int) $row->shift_template_id : null,
                'shift_code' => $row->shift_code,
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'duration_minutes' => (int) $row->duration_minutes,
                'required_resources_count' => (int) $row->required_resources_count,
                'metadata' => json_decode($row->metadata ?? '[]', true) ?: [],
            ])->all();

        $requiredSkillsBySlot = [];
        foreach (DB::table('demand_slot_required_skill')->get() as $row) {
            $requiredSkillsBySlot[(int) $row->demand_slot_id][] = (int) $row->skill_id;
        }

        $absences = [];
        foreach (DB::table('absences')->get() as $row) {
            $absences[(int) $row->resource_id][] = [
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'blocks_planning' => (bool) $row->blocks_planning,
                'counts_as_work_time' => (bool) $row->counts_as_work_time,
                'nominal_minutes' => (int) ($row->nominal_minutes ?? 0),
            ];
        }

        $availabilityRules = [];
        foreach (DB::table('availability_rules')
            ->where(function ($query) use ($period): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $period['ends_on']);
            })
            ->where(function ($query) use ($period): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $period['starts_on']);
            })
            ->get() as $row) {
            $availabilityRules[(int) $row->resource_id][] = [
                'name' => $row->name,
                'rule_type' => $row->rule_type,
                'day_of_week' => $row->day_of_week ? (int) $row->day_of_week : null,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
                'priority' => (int) $row->priority,
                'metadata' => json_decode($row->metadata ?? '[]', true) ?: [],
            ];
        }

        $holidays = DB::table('calendar_holidays')
            ->whereBetween('holiday_date', [$period['starts_on'], $period['ends_on']])
            ->get()
            ->map(fn ($row): array => [
                'holiday_date' => $row->holiday_date,
                'name' => $row->name,
                'scope' => $row->scope,
                'resource_id' => $row->resource_id ? (int) $row->resource_id : null,
                'resource_group_id' => $row->resource_group_id ? (int) $row->resource_group_id : null,
                'blocks_planning' => (bool) $row->blocks_planning,
            ])
            ->all();

        $limits = DB::table('resource_planning_limits')->where(function ($query) use ($planningPeriodId): void {
            $query->whereNull('planning_period_id')->orWhere('planning_period_id', $planningPeriodId);
        })->get()->keyBy('resource_id')->map(fn ($row): array => [
            'max_minutes_per_day' => $row->max_minutes_per_day,
            'max_minutes_per_month' => $row->max_minutes_per_month,
            'max_minutes_per_quarter' => $row->max_minutes_per_quarter,
            'target_minutes_per_month' => $row->target_minutes_per_month,
            'target_minutes_per_quarter' => $row->target_minutes_per_quarter,
            'min_rest_minutes' => $row->min_rest_minutes,
        ])->all();

        $unitRules = [];
        foreach (DB::table('planning_unit_resource_rules')->get() as $row) {
            $shiftTemplateId = $row->shift_template_id ? (int) $row->shift_template_id : 0;
            $unitRules[(int) $row->planning_unit_id][$shiftTemplateId][(int) $row->resource_id] = [
                'shift_template_id' => $row->shift_template_id ? (int) $row->shift_template_id : null,
                'usage_mode' => $row->usage_mode,
                'priority' => (int) $row->priority,
                'penalty' => (int) $row->penalty,
            ];
        }

        $substitutionPolicies = [];
        foreach (DB::table('resource_substitution_policies')->get() as $row) {
            $substitutionPolicies[] = [
                'resource_id' => (int) $row->resource_id,
                'primary_planning_unit_id' => (int) $row->primary_planning_unit_id,
                'primary_shift_template_id' => $row->primary_shift_template_id ? (int) $row->primary_shift_template_id : null,
                'when_used_as_usage_mode' => $row->when_used_as_usage_mode,
                'effect' => $row->effect,
            ];
        }

        $locked = [];
        foreach (DB::table('assignments')->where('planning_period_id', $planningPeriodId)->where('is_locked', true)->get() as $row) {
            $locked[((int) $row->demand_slot_id).':'.((int) $row->slot_position)] = $row->resource_id ? (int) $row->resource_id : null;
        }

        return new PlanningProblem(
            (int) $period['id'],
            CarbonImmutable::parse($period['starts_on']),
            CarbonImmutable::parse($period['ends_on']),
            (int) $period['monthly_norm_minutes'],
            (int) $period['quarterly_norm_minutes'],
            $resources,
            $skillsByResource,
            DB::table('planning_units')->get()->keyBy('id')->map(fn ($row): array => (array) $row)->all(),
            $slots,
            $requiredSkillsBySlot,
            $absences,
            $availabilityRules,
            $holidays,
            $limits,
            $unitRules,
            $substitutionPolicies,
            $locked,
        );
    }
}
