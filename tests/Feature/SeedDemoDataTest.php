<?php

use App\Planning\Engine\CandidatePool\DefaultCandidatePoolBuilder;
use App\Planning\Infrastructure\EloquentPlanningProblemFactory;
use App\Planning\Infrastructure\PlanningRuleSettings;
use App\Planning\Jobs\SeedDemoData;
use Illuminate\Support\Facades\DB;

it('seeds a continuous three-shift vehicle fleet scenario from synthetic source data', function (): void {
    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    $period = DB::table('planning_periods')->first();
    $periodMetadata = json_decode($period->metadata, true);
    $resourceMetadata = DB::table('resources')->pluck('metadata')->map(fn (string $metadata): array => json_decode($metadata, true));
    $unitMetadata = DB::table('planning_units')->pluck('metadata')->map(fn (string $metadata): array => json_decode($metadata, true));
    $shiftCounts = DB::table('demand_slots')
        ->join('shift_templates', 'shift_templates.id', '=', 'demand_slots.shift_template_id')
        ->selectRaw('shift_templates.code, count(*) as slots_count')
        ->groupBy('shift_templates.code')
        ->pluck('slots_count', 'shift_templates.code')
        ->map(fn ($count): int => (int) $count);
    $contractPreferredMinutes = $resourceMetadata
        ->where('driver_mode', 'contract')
        ->sum(fn (array $metadata): int => (int) $metadata['preferred_max_minutes']);
    $employeeTargetMinutes = (int) DB::table('resource_planning_limits')->sum('target_minutes_per_month');
    $demandMinutes = (int) DB::table('demand_slots')->sum('duration_minutes');
    $paidAbsenceMinutes = (int) DB::table('absences')->where('counts_as_work_time', true)->sum('nominal_minutes');

    expect($periodMetadata['demo_scenario'])->toBe('vehicles')
        ->and($periodMetadata['source_checksum'])->toHaveLength(64)
        ->and((int) $period->monthly_norm_minutes)->toBe(11040)
        ->and(DB::table('planning_units')->count())->toBe(12)
        ->and($unitMetadata->countBy('sector_code')->sortKeys()->all())->toBe(['central' => 4, 'north' => 4, 'south' => 4])
        ->and(DB::table('shift_templates')->count())->toBe(3)
        ->and(DB::table('shift_templates')->where('duration_minutes', 480)->count())->toBe(3)
        ->and($shiftCounts->sortKeys()->all())->toBe(['AFTERNOON_8H' => 372, 'MORNING_8H' => 372, 'NIGHT_8H' => 372])
        ->and(DB::table('demand_slots')->count())->toBe(1116)
        ->and(DB::table('demand_slots')->where('duration_minutes', 480)->count())->toBe(1116)
        ->and(DB::table('resources')->count())->toBe(51)
        ->and($resourceMetadata->countBy('driver_mode')->sortKeys()->all())->toBe(['contract' => 3, 'dedicated' => 42, 'flex' => 6])
        ->and(DB::table('resources')->where('nominal_workday_minutes', 480)->count())->toBe(51)
        ->and(DB::table('resource_planning_limits')->where('target_minutes_per_month', 11040)->count())->toBe(48)
        ->and(DB::table('resource_planning_limits')->whereNull('target_minutes_per_month')->count())->toBe(3)
        ->and(DB::table('absences')->count())->toBe(5)
        ->and($paidAbsenceMinutes)->toBe(7200)
        ->and($demandMinutes - $employeeTargetMinutes)->toBe(5760)
        ->and($demandMinutes - $employeeTargetMinutes + $paidAbsenceMinutes)->toBe(12960)
        ->and($contractPreferredMinutes)->toBe(12960);

    $shifts = DB::table('shift_templates')->get()->keyBy('code');
    expect($shifts['MORNING_8H']->name)->toBe('Zmiana I')
        ->and($shifts['AFTERNOON_8H']->name)->toBe('Zmiana II')
        ->and($shifts['NIGHT_8H']->name)->toBe('Zmiana III')
        ->and(substr($shifts['MORNING_8H']->start_time, 0, 5))->toBe('06:00')
        ->and(substr($shifts['MORNING_8H']->end_time, 0, 5))->toBe('14:00')
        ->and(substr($shifts['AFTERNOON_8H']->start_time, 0, 5))->toBe('14:00')
        ->and(substr($shifts['AFTERNOON_8H']->end_time, 0, 5))->toBe('22:00')
        ->and(substr($shifts['NIGHT_8H']->start_time, 0, 5))->toBe('22:00')
        ->and(substr($shifts['NIGHT_8H']->end_time, 0, 5))->toBe('06:00')
        ->and((bool) $shifts['NIGHT_8H']->crosses_midnight)->toBeTrue();

    $fuelSkillId = (int) DB::table('skills')->where('code', 'fuel_transport')->value('id');
    $fuelUnitId = (int) DB::table('planning_unit_required_skill')->where('skill_id', $fuelSkillId)->value('planning_unit_id');
    $fuelSlotId = (int) DB::table('demand_slots')->where('planning_unit_id', $fuelUnitId)->value('id');
    expect(DB::table('demand_slot_required_skill')->where('demand_slot_id', $fuelSlotId)->pluck('skill_id')->all())
        ->toContain($fuelSkillId);

    $problem = app(EloquentPlanningProblemFactory::class)->make((int) $period->id);
    $candidatePool = app(DefaultCandidatePoolBuilder::class)->build($problem);
    expect($candidatePool->candidatesByGene)->toHaveCount(1116)
        ->and(collect($candidatePool->candidatesByGene)->filter(fn (array $candidates): bool => $candidates === []))->toBeEmpty();

    $this->withoutVite();
    $response = $this->get('/');
    $scheduleRows = collect($response->inertiaProps('scheduleRows'));
    expect($response->inertiaProps('period')['metadata']['demo_scenario'])->toBe('vehicles')
        ->and($scheduleRows)->toHaveCount(36)
        ->and($scheduleRows->take(3)->pluck('unit_code')->all())->toBe([
            'vehicle_north_01',
            'vehicle_north_01',
            'vehicle_north_01',
        ])->and($scheduleRows->take(3)->pluck('shift_code')->all())->toBe([
            'MORNING_8H',
            'AFTERNOON_8H',
            'NIGHT_8H',
        ])->and($scheduleRows->pluck('sector_code')->unique()->values()->all())->toBe([
            'north',
            'central',
            'south',
        ])->and($scheduleRows->groupBy('unit_code')->map->count()->unique()->values()->all())->toBe([3])
        ->and($response->inertiaProps('resources'))->toHaveCount(51)
        ->and($response->inertiaProps('absences'))->toHaveCount(5);
});

it('keeps only vehicle-relevant planning rules active and visible', function (): void {
    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    $inactiveCodes = DB::table('planning_rule_settings')
        ->where('is_active', false)
        ->orderBy('code')
        ->pluck('code')
        ->all();
    $visibleCodes = collect(PlanningRuleSettings::all())->pluck('code')->all();
    $solverSettings = PlanningRuleSettings::applyToConfig();

    expect($inactiveCodes)->toBe([
        'flex_resource_one_split_per_day',
        'quarterly_limit_exceeded',
        'senior_coverage_missing',
        'spread_partial_top_ups',
    ])->and($visibleCodes)->not->toContain(
        'senior_coverage_missing',
        'quarterly_limit_exceeded',
        'spread_partial_top_ups',
        'flex_resource_one_split_per_day',
    )->and($visibleCodes)->toContain(
        'missing_skill',
        'absence_conflict',
        'min_rest_violation',
        'contract_usage',
        'shift_balance',
    )->and($solverSettings['senior_coverage_missing']['is_active'])->toBeFalse()
        ->and($solverSettings['absence_conflict']['is_active'])->toBeTrue();
});

it('assigns dedicated, two-sector and contract drivers to the correct usage tiers', function (): void {
    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    $drivers = DB::table('resources')->get()->map(function ($driver): array {
        return ['id' => (int) $driver->id, ...json_decode($driver->metadata, true)];
    });
    $units = DB::table('planning_units')->get()->mapWithKeys(function ($unit): array {
        $metadata = json_decode($unit->metadata, true);

        return [$metadata['sector_code'] => [...$metadata, 'id' => (int) $unit->id]];
    });
    $usageMode = fn (int $resourceId, int $unitId): array => DB::table('planning_unit_resource_rules')
        ->where('resource_id', $resourceId)
        ->where('planning_unit_id', $unitId)
        ->pluck('usage_mode')
        ->unique()
        ->values()
        ->all();

    $dedicated = $drivers->first(fn (array $driver): bool => $driver['driver_mode'] === 'dedicated' && $driver['primary_sector_code'] === 'north');
    expect($usageMode($dedicated['id'], $units['north']['id']))->toBe(['primary'])
        ->and($usageMode($dedicated['id'], $units['central']['id']))->toBe(['excluded'])
        ->and($usageMode($dedicated['id'], $units['south']['id']))->toBe(['excluded']);

    $flex = $drivers->firstWhere('driver_mode', 'flex');
    $excludedSector = collect(['north', 'central', 'south'])->first(fn (string $sector): bool => ! in_array($sector, $flex['secondary_sector_codes'], true));
    foreach ($flex['secondary_sector_codes'] as $sectorCode) {
        expect($usageMode($flex['id'], $units[$sectorCode]['id']))->toBe(['secondary']);
    }
    expect($usageMode($flex['id'], $units[$excludedSector]['id']))->toBe(['excluded']);

    $contract = $drivers->firstWhere('driver_mode', 'contract');
    expect(DB::table('planning_unit_resource_rules')->where('resource_id', $contract['id'])->pluck('usage_mode')->unique()->values()->all())
        ->toBe(['fallback'])
        ->and(DB::table('planning_unit_resource_rules')->whereIn('usage_mode', ['secondary', 'fallback'])->where('penalty', '!=', 0)->count())
        ->toBe(0);
});

it('keeps vehicle demo identifiers stable when the same scenario is reseeded', function (): void {
    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    $before = [
        'resources' => DB::table('resources')->orderBy('employee_number')->pluck('id')->all(),
        'units' => DB::table('planning_units')->orderBy('code')->pluck('id')->all(),
        'slots' => DB::table('demand_slots')->orderBy('starts_at')->orderBy('planning_unit_id')->pluck('id')->all(),
        'rule_count' => DB::table('planning_unit_resource_rules')->count(),
    ];

    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    expect(DB::table('resources')->orderBy('employee_number')->pluck('id')->all())->toBe($before['resources'])
        ->and(DB::table('planning_units')->orderBy('code')->pluck('id')->all())->toBe($before['units'])
        ->and(DB::table('demand_slots')->orderBy('starts_at')->orderBy('planning_unit_id')->pluck('id')->all())->toBe($before['slots'])
        ->and(DB::table('planning_unit_resource_rules')->count())->toBe($before['rule_count'])
        ->and(DB::table('demand_slots')->count())->toBe(1116);
});

it('switches demo scenarios without mixing medical and vehicle planning data', function (): void {
    $this->seed();
    expect(DB::table('planning_units')->where('code', 'ward_manager')->exists())->toBeTrue();

    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);
    expect(DB::table('planning_units')->where('code', 'ward_manager')->exists())->toBeFalse()
        ->and(DB::table('planning_units')->where('code', 'like', 'vehicle_%')->count())->toBe(12)
        ->and(DB::table('resources')->count())->toBe(51);

    SeedDemoData::dispatchSync(SeedDemoData::MEDICAL);
    expect(DB::table('planning_units')->where('code', 'like', 'vehicle_%')->exists())->toBeFalse()
        ->and(DB::table('planning_units')->where('code', 'ward_manager')->exists())->toBeTrue()
        ->and(DB::table('resources')->count())->toBe(24)
        ->and(json_decode(DB::table('planning_periods')->value('metadata'), true)['demo_scenario'])->toBe('medical')
        ->and((bool) DB::table('planning_rule_settings')->where('code', 'senior_coverage_missing')->value('is_active'))->toBeTrue()
        ->and(collect(PlanningRuleSettings::all())->pluck('code')->all())->toContain('senior_coverage_missing');
});

it('rebuilds the active demo when its deterministic source checksum changes', function (): void {
    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    DB::table('resources')->insert([
        'employee_number' => 99999,
        'external_code' => 'STALE-DEMO-RESOURCE',
        'name' => 'Stary zasób demonstracyjny',
        'is_active' => true,
        'nominal_workday_minutes' => 480,
        'metadata' => json_encode(['demo_scenario' => 'vehicles']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $metadata = json_decode(DB::table('planning_periods')->value('metadata'), true);
    DB::table('planning_periods')->update(['metadata' => json_encode([...$metadata, 'source_checksum' => str_repeat('0', 64)])]);

    SeedDemoData::dispatchSync(SeedDemoData::VEHICLES);

    expect(DB::table('resources')->where('external_code', 'STALE-DEMO-RESOURCE')->exists())->toBeFalse()
        ->and(DB::table('resources')->count())->toBe(51)
        ->and(json_decode(DB::table('planning_periods')->value('metadata'), true)['source_checksum'])->not->toBe(str_repeat('0', 64));
});

it('rejects unknown demo scenarios before changing planning data', function (): void {
    expect(fn () => SeedDemoData::dispatchSync('unknown'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('planning_periods')->count())->toBe(0)
        ->and(DB::table('resources')->count())->toBe(0);

    $this->artisan('demo:seed unknown')->assertExitCode(1);
});

it('refuses to replace unrecognized planning data with a demo scenario', function (): void {
    DB::table('planning_periods')->insert([
        'name' => 'Dane użytkownika',
        'type' => 'month',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-31',
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => SeedDemoData::dispatchSync(SeedDemoData::VEHICLES))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('planning_periods')->value('name'))->toBe('Dane użytkownika')
        ->and(DB::table('planning_periods')->count())->toBe(1);
});
