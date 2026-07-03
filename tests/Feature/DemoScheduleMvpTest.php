<?php

use App\Planning\Engine\CandidatePool\DefaultCandidatePoolBuilder;
use App\Planning\Engine\Contracts\SolverInterface;
use App\Planning\Infrastructure\EloquentPlanningProblemFactory;
use App\Planning\Infrastructure\EloquentPlanningResultPersister;
use App\Planning\Jobs\RunPlanningJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('loads demo image data and per resource limits from json source', function (): void {
    $this->seed();

    $wardManagerSkillId = DB::table('skills')->where('code', 'ward_manager')->value('id');
    $wardSkillId = DB::table('skills')->where('code', 'ward')->value('id');
    $deliveryRoomSkillId = DB::table('skills')->where('code', 'delivery_room')->value('id');
    $newbornsSkillId = DB::table('skills')->where('code', 'newborns')->value('id');
    $wardSeniorSkillId = DB::table('skills')->where('code', 'ward_senior')->value('id');
    $resource1Id = DB::table('resources')->where('employee_number', 1)->value('id');
    $resource5Id = DB::table('resources')->where('employee_number', 5)->value('id');
    $resource16Id = DB::table('resources')->where('employee_number', 16)->value('id');
    $resource7Id = DB::table('resources')->where('employee_number', 7)->value('id');
    $resource17Id = DB::table('resources')->where('employee_number', 17)->value('id');
    $wardManagerUnitId = DB::table('planning_units')->where('code', 'ward_manager')->value('id');
    $seniorWardUnitId = DB::table('planning_units')->where('code', 'senior_ward')->value('id');
    $shortDayShiftId = DB::table('shift_templates')->where('code', 'SHORT_DAY')->value('id');
    $day12ShiftId = DB::table('shift_templates')->where('code', 'DAY_12H')->value('id');
    $resource1Skills = DB::table('resource_skill')->where('resource_id', $resource1Id)->pluck('skill_id')->all();
    $resource5Skills = DB::table('resource_skill')->where('resource_id', $resource5Id)->pluck('skill_id')->all();
    $resource7Skills = DB::table('resource_skill')->where('resource_id', $resource7Id)->pluck('skill_id')->all();
    $resource17Metadata = json_decode(DB::table('resources')->where('id', $resource17Id)->value('metadata'), true);
    $resource5Metadata = json_decode(DB::table('resources')->where('id', $resource5Id)->value('metadata'), true);
    $resource16Metadata = json_decode(DB::table('resources')->where('id', $resource16Id)->value('metadata'), true);
    $contractRuleMetadata = json_decode(DB::table('planning_rule_settings')->where('code', 'contract_usage')->value('metadata'), true);
    $spreadRuleMetadata = json_decode(DB::table('planning_rule_settings')->where('code', 'spread_partial_top_ups')->value('metadata'), true);
    $wardManagerRuleMetadata = json_decode(DB::table('planning_rule_settings')->where('code', 'ward_manager_one_split_per_day')->value('metadata'), true);
    $evenNightsRuleMetadata = json_decode(DB::table('planning_rule_settings')->where('code', 'even_nights')->value('metadata'), true);
    $resource5VacationMinutes = DB::table('absences')->where('resource_id', $resource5Id)->sum('nominal_minutes');
    $resource12Id = DB::table('resources')->where('employee_number', 12)->value('id');

    expect(DB::table('resources')->count())->toBe(24)
        ->and(DB::table('planning_units')->where('code', 'ward_manager')->value('name'))->toBe('Oddziałowa')
        ->and(DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('monthly_norm_minutes'))->toBe(10465)
        ->and(DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('quarterly_norm_minutes'))->toBe(29570)
        ->and(config('planning.solver.time_limit_seconds'))->toBe(300)
        ->and(DB::table('absences')->where('source', 'image')->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('resource_planning_limits')->whereNotNull('target_minutes_per_month')->whereNotNull('target_minutes_per_quarter')->count())->toBe(16)
        ->and(DB::table('resource_skill')->where('skill_id', $wardManagerSkillId)->pluck('resource_id')->all())->toBe([$resource1Id])
        ->and($resource1Skills)->toContain($wardSkillId, $wardSeniorSkillId)
        ->and($resource5Skills)->toContain($deliveryRoomSkillId, $wardSkillId, $wardSeniorSkillId)
        ->and($resource5Skills)->not->toContain($newbornsSkillId)
        ->and((int) $resource5VacationMinutes)->toBe(4800)
        ->and((int) DB::table('absences')->where('resource_id', $resource12Id)->sum('nominal_minutes'))->toBe(3840)
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource5Id)->value('target_minutes_per_month'))->toBe(10465)
        ->and($resource5Metadata['planned_duties_expression_raw'])->toBe('7x12+6,35')
        ->and($resource5Metadata['planned_duties_minutes'])->toBe(5435)
        ->and($resource5Metadata['employment_fraction'])->toBe(1)
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource16Id)->value('target_minutes_per_month'))->toBe(7849)
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource16Id)->value('target_minutes_per_quarter'))->toBe(22178)
        ->and($resource16Metadata['employment_fraction'])->toBe(0.75)
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource5Id)->value('max_minutes_per_month'))->toBe(DB::table('resource_planning_limits')->where('resource_id', $resource5Id)->value('target_minutes_per_month'))
        ->and($resource7Skills)->toContain($newbornsSkillId)
        ->and($resource7Skills)->not->toContain($deliveryRoomSkillId)
        ->and($resource17Metadata['employment_type'])->toBe('contract')
        ->and($resource17Metadata['workload_policy'])->toBe('minimize_usage')
        ->and($resource17Metadata['preferred_max_minutes'])->toBe(2160)
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource17Id)->value('target_minutes_per_month'))->toBeNull()
        ->and(DB::table('resource_planning_limits')->where('resource_id', $resource17Id)->value('max_minutes_per_month'))->toBeNull()
        ->and(DB::table('planning_rule_settings')->where('code', 'consecutive_nights')->value('weight'))->toBe(250000)
        ->and(DB::table('planning_rule_settings')->where('code', 'contract_usage')->value('weight'))->toBe(2000)
        ->and(DB::table('planning_rule_settings')->where('code', 'avoid_same_resource_streaks')->value('weight'))->toBe(80000)
        ->and(DB::table('planning_rule_settings')->where('code', 'spread_partial_top_ups')->value('weight'))->toBe(5000)
        ->and($contractRuleMetadata['minimum_assignments_per_active_resource'])->toBe(1)
        ->and($spreadRuleMetadata['minimum_employee_segment_minutes'])->toBe(360)
        ->and($wardManagerRuleMetadata['max_splits_per_day'])->toBe(1)
        ->and($wardManagerRuleMetadata['max_prefixes_per_period'])->toBe(4)
        ->and($wardManagerRuleMetadata['prefix_minutes'])->toBe(335)
        ->and($wardManagerRuleMetadata['allowed_shift_codes'])->toBe(['DAY_12H'])
        ->and((bool) DB::table('planning_rule_settings')->where('code', 'ward_manager_one_split_per_day')->value('can_toggle'))->toBeFalse()
        ->and(DB::table('planning_rule_settings')->where('code', 'even_nights')->value('weight'))->toBe(3000)
        ->and($evenNightsRuleMetadata['min_night_share_percent'])->toBe(25)
        ->and($evenNightsRuleMetadata['max_night_share_percent'])->toBe(60)
        ->and($evenNightsRuleMetadata['min_assignments_for_share'])->toBe(3)
        ->and(DB::table('planning_rule_settings')->where('code', 'even_weekends')->value('weight'))->toBe(500)
        ->and(DB::table('planning_unit_resource_rules')->where('resource_id', $resource17Id)->where('shift_template_id', $day12ShiftId)->pluck('usage_mode')->unique()->values()->all())->toBe(['fallback'])
        ->and(DB::table('availability_rules')->where('resource_id', $resource1Id)->where('rule_type', 'unavailable')->pluck('day_of_week')->sort()->values()->all())->toBe([6, 7])
        ->and(DB::table('planning_unit_resource_rules')->where('resource_id', $resource1Id)->where('planning_unit_id', $wardManagerUnitId)->where('shift_template_id', $shortDayShiftId)->value('usage_mode'))->toBe('primary')
        ->and(DB::table('planning_unit_resource_rules')->where('resource_id', $resource1Id)->where('planning_unit_id', $seniorWardUnitId)->where('shift_template_id', $day12ShiftId)->value('usage_mode'))->toBe('fallback')
        ->and(DB::table('resource_substitution_policies')->count())->toBe(1);
});

it('renders the schedule page', function (): void {
    $this->withoutVite();
    $this->seed();

    $this->get('/')->assertOk();

    $resources = $this->get('/')->inertiaProps('resources');
    $scheduleRows = $this->get('/')->inertiaProps('scheduleRows');
    $resource5 = collect($resources)->firstWhere('employee_number', 5);
    $resource1 = collect($resources)->firstWhere('employee_number', 1);

    expect($resource5['planned_duties_note'])->toBe('7x12h + 1x6:35')
        ->and($resource1['planned_duties_note'])->toBe('23x7:35')
        ->and(collect($scheduleRows)->pluck('unit_code')->unique()->sort()->values()->all())->toBe([
            'delivery_room',
            'newborns',
            'senior_ward',
            'ward_manager',
        ]);
});

it('keeps demand slot identifiers stable when demo seed is rerun', function (): void {
    $this->seed();

    $before = DB::table('demand_slots')
        ->orderBy('starts_at')
        ->orderBy('planning_unit_id')
        ->orderBy('shift_template_id')
        ->pluck('id')
        ->all();

    $this->seed();

    $after = DB::table('demand_slots')
        ->orderBy('starts_at')
        ->orderBy('planning_unit_id')
        ->orderBy('shift_template_id')
        ->pluck('id')
        ->all();

    expect($after)->toBe($before)
        ->and(DB::table('demand_slots')->count())->toBe(209);
});

it('restores default planning rule weights when demo seed is rerun', function (): void {
    $this->seed();

    DB::table('planning_rule_settings')->where('code', 'consecutive_nights')->update([
        'weight' => 123,
        'updated_at' => now(),
    ]);

    $this->seed();

    expect(DB::table('planning_rule_settings')->where('code', 'consecutive_nights')->value('weight'))->toBe(250000);
});

it('includes resource details for schedule violations', function (): void {
    $this->withoutVite();
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $resourceId = (int) DB::table('resources')->where('employee_number', 1)->value('id');
    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'completed',
        'solver_name' => 'test',
        'random_seed' => 1,
        'hard_violations_count' => 1,
        'config' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('planning_run_violations')->insert([
        'planning_run_id' => $runId,
        'code' => 'nominal_underfilled',
        'severity' => 'hard',
        'message' => 'Etatowiec nie ma dopełnionego nominału.',
        'resource_id' => $resourceId,
        'demand_slot_id' => null,
        'metadata' => json_encode(['missing_minutes' => 145, 'planned_work_minutes' => 10320, 'paid_absence_minutes' => 0, 'target_minutes' => 10465]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $violations = $this->get('/')->inertiaProps('violations');

    expect($violations[0]['employee_number'])->toBe(1)
        ->and($violations[0]['resource_name'])->toBeString()
        ->and($violations[0]['metadata']['missing_minutes'])->toBe(145);
});

it('separates demand coverage from technical top ups in the schedule payload', function (): void {
    $this->withoutVite();
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'completed',
        'solver_name' => 'test',
        'random_seed' => 1,
        'config' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $slot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->whereDate('demand_slots.starts_at', '2026-07-01')
        ->where('planning_units.code', 'delivery_room')
        ->where('shift_templates.code', 'DAY_12H')
        ->first(['demand_slots.*']);
    $resourceId = (int) DB::table('resources')->where('employee_number', 2)->value('id');
    $wardManagerId = (int) DB::table('resources')->where('employee_number', 1)->value('id');

    foreach ([
        [1, $resourceId, $slot->starts_at, $slot->ends_at, 720, []],
        [2, $resourceId, '2026-07-01 14:35:00', $slot->ends_at, 265, ['segment_kind' => 'supplementary_nominal_top_up', 'covers_demand' => false]],
        [3, $wardManagerId, $slot->starts_at, '2026-07-01 12:35:00', 335, ['segment_kind' => 'ward_manager_contract_split_prefix']],
    ] as [$position, $assignmentResourceId, $startsAt, $endsAt, $duration, $metadata]) {
        DB::table('assignments')->insert([
            'planning_period_id' => $periodId,
            'demand_slot_id' => $slot->id,
            'slot_position' => $position,
            'segment_position' => 1,
            'resource_id' => $assignmentResourceId,
            'planning_run_id' => $runId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => $duration,
            'source' => 'test',
            'is_locked' => false,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $payloadAssignments = collect($this->get('/')->inertiaProps('assignments'))->sortBy('slot_position')->values();

    expect($payloadAssignments->pluck('display_layer')->all())->toBe(['demand', 'resource_only', 'top_up'])
        ->and($payloadAssignments->pluck('unit_code')->unique()->values()->all())->toBe(['delivery_room']);
});

it('detects zero rest conflicts during result post processing', function (): void {
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $resourceId = (int) DB::table('resources')->where('employee_number', 13)->value('id');
    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'completed',
        'solver_name' => 'test',
        'random_seed' => 1,
        'config' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $nightSlot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->whereDate('demand_slots.starts_at', '2026-07-27')
        ->where('planning_units.code', 'newborns')
        ->where('shift_templates.code', 'NIGHT_12H')
        ->first(['demand_slots.*']);

    DB::table('assignments')->insert([
        'planning_period_id' => $periodId,
        'demand_slot_id' => $nightSlot->id,
        'slot_position' => 1,
        'segment_position' => 1,
        'resource_id' => $resourceId,
        'planning_run_id' => $runId,
        'starts_at' => $nightSlot->starts_at,
        'ends_at' => $nightSlot->ends_at,
        'duration_minutes' => $nightSlot->duration_minutes,
        'source' => 'test',
        'is_locked' => false,
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $method = new ReflectionMethod(EloquentPlanningResultPersister::class, 'hasAssignmentConflict');
    $method->setAccessible(true);

    expect($method->invoke(
        new EloquentPlanningResultPersister,
        $runId,
        $periodId,
        $resourceId,
        CarbonImmutable::parse('2026-07-28 07:00:00'),
        CarbonImmutable::parse('2026-07-28 19:00:00'),
    ))->toBeTrue();
});

it('completes a planning job and writes valid assignments', function (): void {
    config()->set('planning.solver.population_size', 12);
    config()->set('planning.solver.generations', 8);
    config()->set('planning.solver.time_limit_seconds', 5);
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'queued',
        'solver_name' => 'genetic',
        'random_seed' => 202607,
        'config' => json_encode(config('planning.solver')),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new RunPlanningJob($runId))->handle(
        app(EloquentPlanningProblemFactory::class),
        app(EloquentPlanningResultPersister::class),
        app(SolverInterface::class),
    );

    $metadata = json_decode(DB::table('planning_runs')->where('id', $runId)->value('metadata'), true);
    $overNominalEmployees = DB::table('resources')
        ->leftJoin('resource_planning_limits', 'resource_planning_limits.resource_id', '=', 'resources.id')
        ->leftJoinSub(
            DB::table('assignments')
                ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
                ->where('assignments.planning_run_id', $runId)
                ->whereNotNull('assignments.resource_id')
                ->groupBy('assignments.resource_id')
                ->selectRaw('assignments.resource_id, sum(coalesce(assignments.duration_minutes, demand_slots.duration_minutes)) as work_minutes'),
            'work',
            'work.resource_id',
            '=',
            'resources.id',
        )
        ->leftJoinSub(
            DB::table('absences')
                ->where('counts_as_work_time', true)
                ->groupBy('resource_id')
                ->selectRaw('resource_id, sum(nominal_minutes) as absence_minutes'),
            'absence',
            'absence.resource_id',
            '=',
            'resources.id',
        )
        ->whereRaw("json_unquote(json_extract(resources.metadata, '$.workload_policy')) <> 'minimize_usage'")
        ->whereRaw('(coalesce(work.work_minutes, 0) + coalesce(absence.absence_minutes, 0)) > resource_planning_limits.target_minutes_per_month')
        ->count();
    $underNominalEmployees = DB::table('resources')
        ->leftJoin('resource_planning_limits', 'resource_planning_limits.resource_id', '=', 'resources.id')
        ->leftJoinSub(
            DB::table('assignments')
                ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
                ->where('assignments.planning_run_id', $runId)
                ->whereNotNull('assignments.resource_id')
                ->groupBy('assignments.resource_id')
                ->selectRaw('assignments.resource_id, sum(coalesce(assignments.duration_minutes, demand_slots.duration_minutes)) as work_minutes'),
            'work',
            'work.resource_id',
            '=',
            'resources.id',
        )
        ->leftJoinSub(
            DB::table('absences')
                ->where('counts_as_work_time', true)
                ->groupBy('resource_id')
                ->selectRaw('resource_id, sum(nominal_minutes) as absence_minutes'),
            'absence',
            'absence.resource_id',
            '=',
            'resources.id',
        )
        ->whereRaw("json_unquote(json_extract(resources.metadata, '$.workload_policy')) <> 'minimize_usage'")
        ->whereRaw('(coalesce(work.work_minutes, 0) + coalesce(absence.absence_minutes, 0)) < resource_planning_limits.target_minutes_per_month')
        ->count();
    $scheduleAssignments = $this->withoutVite()->get('/')->inertiaProps('assignments');
    $nominalTopUpTailInPayload = collect($scheduleAssignments)
        ->contains(fn (array $assignment): bool => ($assignment['metadata']['segment_kind'] ?? null) === 'nominal_top_up_tail');
    $resource5Id = (int) DB::table('resources')->where('employee_number', 5)->value('id');
    $wardManagerId = (int) DB::table('resources')->where('employee_number', 1)->value('id');
    $maxWardManagerSplitsPerDay = (int) DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('resource_id', $wardManagerId)
        ->where('metadata', 'like', '%"segment_kind":"ward_manager_prefix"%')
        ->selectRaw('date(starts_at) as day, count(*) as split_count')
        ->groupBy('day')
        ->get()
        ->max('split_count');
    $wardManagerPrefixes = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('resource_id', $wardManagerId)
        ->where('metadata', 'like', '%"segment_kind":"ward_manager_prefix"%')
        ->count();
    $wardManagerNightTopUps = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->where('assignments.planning_run_id', $runId)
        ->where('assignments.resource_id', $wardManagerId)
        ->where(function ($query): void {
            $query->where('assignments.metadata', 'like', '%"segment_kind":"ward_manager_prefix"%')
                ->orWhere('assignments.metadata', 'like', '%"segment_kind":"supplementary_nominal_top_up"%');
        })
        ->where('shift_templates.code', '<>', 'DAY_12H')
        ->count();
    $wardManagerTopUps = DB::table('assignments')
        ->where('assignments.planning_run_id', $runId)
        ->where('assignments.resource_id', $wardManagerId)
        ->where(function ($query): void {
            $query->where('assignments.metadata', 'like', '%"segment_kind":"ward_manager_prefix"%')
                ->orWhere('assignments.metadata', 'like', '%"segment_kind":"supplementary_nominal_top_up"%');
        })
        ->get(['assignments.demand_slot_id']);
    $wardManagerSkillIds = DB::table('resource_skill')->where('resource_id', $wardManagerId)->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
    $wardManagerTopUpsMissingSkills = $wardManagerTopUps
        ->filter(function ($assignment) use ($wardManagerSkillIds): bool {
            $requiredSkills = DB::table('demand_slot_required_skill')
                ->where('demand_slot_id', $assignment->demand_slot_id)
                ->pluck('skill_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            return array_diff($requiredSkills, $wardManagerSkillIds) !== [];
        })
        ->count();
    $releasedWardManagerPrimaryAssignments = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('source', 'generated_released_for_partial_top_up')
        ->count();
    $wardManagerAssignmentsByOtherResources = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->where('assignments.planning_run_id', $runId)
        ->where('planning_units.code', 'ward_manager')
        ->whereNotNull('assignments.resource_id')
        ->where('assignments.resource_id', '<>', $wardManagerId)
        ->count();
    $contractReductions = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('metadata', 'like', '%"segment_kind":"contract_prefix_reduced"%')
        ->count();
    $contractFullReassignments = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('metadata', 'like', '%"segment_kind":"contract_reassigned_full"%')
        ->count();
    $fullSupplementaryTopUps = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->where('assignments.planning_run_id', $runId)
        ->where('assignments.metadata', 'like', '%"segment_kind":"supplementary_nominal_top_up"%')
        ->whereColumn('assignments.duration_minutes', '>=', 'demand_slots.duration_minutes')
        ->count();
    $nonWardManagerSupplementaryTopUps = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('metadata', 'like', '%"segment_kind":"supplementary_nominal_top_up"%')
        ->where('resource_id', '<>', $wardManagerId)
        ->count();
    $shortStandardPartialSegments = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->whereIn('source', ['generated_partial_nominal_top_up', 'generated_contract_reduced_for_nominal_top_up'])
        ->where('duration_minutes', '<', 360)
        ->count();
    $maxNominalTailsPerDemandSlot = (int) DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('metadata', 'like', '%"segment_kind":"nominal_top_up_tail"%')
        ->selectRaw('demand_slot_id, count(*) as split_count')
        ->groupBy('demand_slot_id')
        ->get()
        ->max('split_count');
    $blockingViolations = DB::table('planning_run_violations')
        ->where('planning_run_id', $runId)
        ->whereIn('code', ['missing_skill', 'senior_coverage_missing', 'absence_conflict', 'availability_conflict', 'overlap', 'min_rest_violation', 'daily_limit_exceeded', 'nominal_limit_exceeded'])
        ->count();
    $hardNominalUnderfills = DB::table('planning_run_violations')
        ->where('planning_run_id', $runId)
        ->where('code', 'nominal_underfilled')
        ->where('severity', 'hard')
        ->count();
    $monthlyCarryovers = DB::table('planning_run_violations')
        ->where('planning_run_id', $runId)
        ->where('code', 'nominal_carryover')
        ->where('severity', 'soft')
        ->count();
    $resource5ShiftCounts = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->where('assignments.planning_run_id', $runId)
        ->where('assignments.resource_id', $resource5Id)
        ->whereIn('shift_templates.code', ['DAY_12H', 'NIGHT_12H'])
        ->groupBy('shift_templates.code')
        ->selectRaw('shift_templates.code as shift_code, count(*) as shifts')
        ->pluck('shifts', 'shift_code')
        ->map(fn ($count): int => (int) $count)
        ->all();
    $activeContractsWithoutAssignment = DB::table('resources')
        ->leftJoinSub(
            DB::table('assignments')
                ->where('planning_run_id', $runId)
                ->whereNotNull('resource_id')
                ->groupBy('resource_id')
                ->selectRaw('resource_id, count(*) as assignments_count'),
            'work',
            'work.resource_id',
            '=',
            'resources.id',
        )
        ->where('resources.is_active', true)
        ->whereRaw("json_unquote(json_extract(resources.metadata, '$.workload_policy')) = 'minimize_usage'")
        ->whereRaw('coalesce(work.assignments_count, 0) = 0')
        ->count();

    expect(DB::table('planning_runs')->where('id', $runId)->value('status'))->toBe('completed')
        ->and(DB::table('assignments')->where('planning_run_id', $runId)->count())->toBeGreaterThan(0)
        ->and($metadata['evaluated_candidates'] ?? 0)->toBeGreaterThan(0)
        ->and($metadata['estimated_candidates'] ?? 0)->toBeGreaterThan(0)
        ->and($metadata['progress_percent'] ?? null)->toBe(100)
        ->and($metadata['stop_reason'] ?? null)->toBeIn(['generations_completed', 'time_limit', 'stagnation'])
        ->and($overNominalEmployees)->toBe(0)
        ->and($hardNominalUnderfills + $monthlyCarryovers)->toBe($underNominalEmployees)
        ->and($hardNominalUnderfills)->toBe(0)
        ->and(DB::table('planning_runs')->where('id', $runId)->value('hard_violations_count'))->toBe($hardNominalUnderfills)
        ->and($nominalTopUpTailInPayload)->toBeFalse()
        ->and($contractReductions)->toBe(0)
        ->and($contractFullReassignments)->toBeGreaterThan(0)
        ->and($wardManagerPrefixes)->toBeGreaterThan(0)
        ->and($maxWardManagerSplitsPerDay)->toBeLessThanOrEqual(1)
        ->and($maxNominalTailsPerDemandSlot)->toBeLessThanOrEqual(1)
        ->and($wardManagerNightTopUps)->toBe(0)
        ->and($wardManagerTopUpsMissingSkills)->toBe(0)
        ->and($releasedWardManagerPrimaryAssignments)->toBe(0)
        ->and($wardManagerAssignmentsByOtherResources)->toBe(0)
        ->and($nonWardManagerSupplementaryTopUps)->toBe(0)
        ->and($fullSupplementaryTopUps)->toBe(0)
        ->and($shortStandardPartialSegments)->toBe(0)
        ->and($resource5ShiftCounts['DAY_12H'] ?? 0)->toBeGreaterThan(0)
        ->and($resource5ShiftCounts['NIGHT_12H'] ?? 0)->toBeLessThanOrEqual(($resource5ShiftCounts['DAY_12H'] ?? 0) + 1)
        ->and($activeContractsWithoutAssignment)->toBe(0)
        ->and($blockingViolations)->toBe(0);
});

it('excludes global holidays from planning', function (): void {
    config()->set('planning.solver.population_size', 8);
    config()->set('planning.solver.generations', 4);
    config()->set('planning.solver.time_limit_seconds', 5);
    $this->seed();

    DB::table('calendar_holidays')->insert([
        'holiday_date' => '2026-07-02',
        'name' => 'Święto testowe',
        'scope' => 'global',
        'resource_id' => null,
        'resource_group_id' => null,
        'blocks_planning' => true,
        'metadata' => json_encode(['source' => 'test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'queued',
        'solver_name' => 'genetic',
        'random_seed' => 202607,
        'config' => json_encode(config('planning.solver')),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new RunPlanningJob($runId))->handle(
        app(EloquentPlanningProblemFactory::class),
        app(EloquentPlanningResultPersister::class),
        app(SolverInterface::class),
    );

    $assignedOnHoliday = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->where('assignments.planning_run_id', $runId)
        ->whereNotNull('assignments.resource_id')
        ->where('demand_slots.starts_at', '<', '2026-07-03 00:00:00')
        ->where('demand_slots.ends_at', '>', '2026-07-02 00:00:00')
        ->count();

    expect(DB::table('planning_runs')->where('id', $runId)->value('status'))->toBe('completed')
        ->and($assignedOnHoliday)->toBe(0)
        ->and(DB::table('planning_run_violations')->where('planning_run_id', $runId)->where('code', 'holiday_conflict')->count())->toBe(0);
});

it('excludes resource scoped holidays from candidate pools', function (): void {
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $resourceId = (int) DB::table('resources')->where('employee_number', 5)->value('id');
    DB::table('calendar_holidays')->insert([
        'holiday_date' => '2026-07-06',
        'name' => 'Święto zasobu',
        'scope' => 'resource',
        'resource_id' => $resourceId,
        'resource_group_id' => null,
        'blocks_planning' => true,
        'metadata' => json_encode(['source' => 'test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $problem = app(EloquentPlanningProblemFactory::class)->make($periodId);
    $pool = (new DefaultCandidatePoolBuilder)->build($problem);
    $holidaySlots = DB::table('demand_slots')
        ->where('planning_period_id', $periodId)
        ->where('starts_at', '<', '2026-07-07 00:00:00')
        ->where('ends_at', '>', '2026-07-06 00:00:00')
        ->get(['id', 'required_resources_count']);

    foreach ($holidaySlots as $slot) {
        for ($position = 1; $position <= (int) $slot->required_resources_count; $position++) {
            $candidateIds = array_column($pool->candidates($problem->geneKey((int) $slot->id, $position)), 'resource_id');
            expect($candidateIds)->not->toContain($resourceId);
        }
    }
});

it('excludes ward manager weekends from candidate pools', function (): void {
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $resource1Id = (int) DB::table('resources')->where('employee_number', 1)->value('id');
    $problem = app(EloquentPlanningProblemFactory::class)->make($periodId);
    $pool = (new DefaultCandidatePoolBuilder)->build($problem);
    $weekendSlots = DB::table('demand_slots')
        ->where('planning_period_id', $periodId)
        ->where('starts_at', '<', '2026-07-06 00:00:00')
        ->where('ends_at', '>', '2026-07-04 00:00:00')
        ->get(['id', 'required_resources_count']);

    foreach ($weekendSlots as $slot) {
        for ($position = 1; $position <= (int) $slot->required_resources_count; $position++) {
            $candidateIds = array_column($pool->candidates($problem->geneKey((int) $slot->id, $position)), 'resource_id');
            expect($candidateIds)->not->toContain($resource1Id);
        }
    }
});

it('allows ward manager to prefix a non senior day slot when another senior covers the group', function (): void {
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $wardManagerId = (int) DB::table('resources')->where('employee_number', 1)->value('id');
    $deliverySkillId = (int) DB::table('skills')->where('code', 'delivery_room')->value('id');
    DB::table('resource_skill')->updateOrInsert(
        ['resource_id' => $wardManagerId, 'skill_id' => $deliverySkillId],
        ['source' => 'test'],
    );

    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'running',
        'solver_name' => 'test',
        'random_seed' => 1,
        'config' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deliverySlot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->whereDate('demand_slots.starts_at', '2026-07-01')
        ->where('planning_units.code', 'delivery_room')
        ->where('shift_templates.code', 'DAY_12H')
        ->first(['demand_slots.*']);
    $seniorWardSlot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->whereDate('demand_slots.starts_at', '2026-07-01')
        ->where('planning_units.code', 'senior_ward')
        ->where('shift_templates.code', 'DAY_12H')
        ->first(['demand_slots.*']);

    $nonSeniorDeliveryResourceId = (int) DB::table('resources')->where('employee_number', 21)->value('id');
    $seniorWardResourceId = (int) DB::table('resources')->where('employee_number', 4)->value('id');
    foreach ([[$deliverySlot, $nonSeniorDeliveryResourceId], [$seniorWardSlot, $seniorWardResourceId]] as $index => [$slot, $resourceId]) {
        DB::table('assignments')->insert([
            'planning_period_id' => $periodId,
            'demand_slot_id' => $slot->id,
            'slot_position' => 1,
            'segment_position' => 1,
            'resource_id' => $resourceId,
            'planning_run_id' => $runId,
            'starts_at' => $slot->starts_at,
            'ends_at' => $slot->ends_at,
            'duration_minutes' => $slot->duration_minutes,
            'source' => 'test',
            'is_locked' => false,
            'metadata' => json_encode(['test_index' => $index]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $persister = new EloquentPlanningResultPersister;
    $method = new ReflectionMethod($persister, 'wardDayAssignmentsForWardManagerPrefix');
    $method->setAccessible(true);
    $candidates = collect($method->invoke($persister, $runId, $wardManagerId));

    expect($candidates->pluck('demand_slot_id')->all())->toContain($deliverySlot->id);
});

it('splits a contract day shift between an underfilled employee and ward manager prefix', function (): void {
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $wardManagerId = (int) DB::table('resources')->where('employee_number', 1)->value('id');
    $employeeId = (int) DB::table('resources')->where('employee_number', 5)->value('id');
    $contractId = (int) DB::table('resources')->where('employee_number', 22)->value('id');

    $runId = DB::table('planning_runs')->insertGetId([
        'planning_period_id' => $periodId,
        'status' => 'running',
        'solver_name' => 'test',
        'random_seed' => 1,
        'config' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $wardManagerSlot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->whereDate('demand_slots.starts_at', '2026-07-01')
        ->where('planning_units.code', 'ward_manager')
        ->first(['demand_slots.*']);
    $seniorWardSlot = DB::table('demand_slots')
        ->join('planning_units', 'planning_units.id', '=', 'demand_slots.planning_unit_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->whereDate('demand_slots.starts_at', '2026-07-01')
        ->where('planning_units.code', 'senior_ward')
        ->where('shift_templates.code', 'DAY_12H')
        ->first(['demand_slots.*']);

    DB::table('assignments')->insert([
        'planning_period_id' => $periodId,
        'demand_slot_id' => $wardManagerSlot->id,
        'slot_position' => 1,
        'segment_position' => 1,
        'resource_id' => $wardManagerId,
        'planning_run_id' => $runId,
        'starts_at' => $wardManagerSlot->starts_at,
        'ends_at' => $wardManagerSlot->ends_at,
        'duration_minutes' => $wardManagerSlot->duration_minutes,
        'source' => 'test',
        'is_locked' => false,
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $assignmentId = DB::table('assignments')->insertGetId([
        'planning_period_id' => $periodId,
        'demand_slot_id' => $seniorWardSlot->id,
        'slot_position' => 1,
        'segment_position' => 1,
        'resource_id' => $contractId,
        'planning_run_id' => $runId,
        'starts_at' => $seniorWardSlot->starts_at,
        'ends_at' => $seniorWardSlot->ends_at,
        'duration_minutes' => $seniorWardSlot->duration_minutes,
        'source' => 'test',
        'is_locked' => false,
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignment = DB::table('assignments')
        ->join('demand_slots', 'demand_slots.id', '=', 'assignments.demand_slot_id')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->where('assignments.id', $assignmentId)
        ->first([
            'assignments.*',
            'shift_templates.code as shift_code',
            'demand_slots.starts_at as slot_starts_at',
            'demand_slots.ends_at as slot_ends_at',
            'demand_slots.metadata as slot_metadata',
        ]);

    $persister = new EloquentPlanningResultPersister;
    $method = new ReflectionMethod($persister, 'splitContractAssignmentBetweenEmployeeAndWardManager');
    $method->setAccessible(true);
    $split = $method->invoke(
        $persister,
        $runId,
        $periodId,
        $wardManagerId,
        $employeeId,
        $assignment,
        385,
        DB::table('resource_skill')->where('resource_id', $employeeId)->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
        DB::table('resource_skill')->where('resource_id', $wardManagerId)->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
    );

    $tail = DB::table('assignments')->where('id', $assignmentId)->first();
    $prefix = DB::table('assignments')
        ->where('planning_run_id', $runId)
        ->where('metadata', 'like', '%"segment_kind":"ward_manager_contract_split_prefix"%')
        ->first();

    expect($split)->toBeTrue()
        ->and((int) $tail->resource_id)->toBe($employeeId)
        ->and((int) $tail->duration_minutes)->toBe(385)
        ->and($tail->starts_at)->toBe('2026-07-01 12:35:00')
        ->and((int) $prefix->resource_id)->toBe($wardManagerId)
        ->and((int) $prefix->duration_minutes)->toBe(335)
        ->and($prefix->starts_at)->toBe('2026-07-01 07:00:00');
});

it('prefers a meaningful employee remainder before taking another full contract shift', function (): void {
    $persister = new EloquentPlanningResultPersister;
    $method = new ReflectionMethod($persister, 'employeePartialMinutesForMissing');
    $method->setAccessible(true);

    expect($method->invoke($persister, 1105, 720))->toBe(385)
        ->and($method->invoke($persister, 865, 720))->toBeNull()
        ->and($method->invoke($persister, 385, 720))->toBe(385);
});
