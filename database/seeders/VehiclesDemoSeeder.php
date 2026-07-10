<?php

namespace Database\Seeders;

use App\Planning\Infrastructure\PlanningRuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class VehiclesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(base_path('sources/vehicles_2026_07.demo.json')), true, flags: JSON_THROW_ON_ERROR);
        PlanningRuleSettings::resetWeightsToDefaults();

        DB::transaction(function () use ($data): void {
            $skillIds = $this->seedSkills($data);
            $groupId = $this->seedResourceGroup($data, $skillIds);
            $periodId = $this->seedPeriod($data);
            $shiftIds = $this->seedShifts($data);
            $unitIds = $this->seedVehicles($data, $skillIds);
            $resourceIds = $this->seedDrivers($data, $groupId, $skillIds, $periodId);
            $absenceTypeIds = $this->seedAbsenceTypes($data);
            $this->seedAbsences($data, $resourceIds, $absenceTypeIds, $periodId);
            $this->seedUnitRules($data, $unitIds, $shiftIds, $resourceIds);
            $this->seedDemandSlots($data, $periodId, $unitIds, $shiftIds, $skillIds);

            DB::table('resource_substitution_policies')->delete();
        });

        PlanningRuleSettings::applyProfile('vehicles');
    }

    private function seedSkills(array $data): array
    {
        $ids = [];
        foreach ($data['skills'] as $skill) {
            DB::table('skills')->updateOrInsert(
                ['code' => $skill['code']],
                [
                    'name' => $skill['name'],
                    'metadata' => json_encode($skill['metadata'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $ids[$skill['code']] = (int) DB::table('skills')->where('code', $skill['code'])->value('id');
        }

        return $ids;
    }

    private function seedResourceGroup(array $data, array $skillIds): int
    {
        $group = $data['resource_group'];
        DB::table('resource_groups')->updateOrInsert(
            ['code' => $group['code']],
            [
                'name' => $group['name'],
                'metadata' => json_encode(['demo_scenario' => 'vehicles']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $groupId = (int) DB::table('resource_groups')->where('code', $group['code'])->value('id');
        DB::table('resource_group_skill')->where('resource_group_id', $groupId)->delete();
        DB::table('resource_group_skill')->insert([
            'resource_group_id' => $groupId,
            'skill_id' => $skillIds[$group['base_skill_code']],
        ]);

        return $groupId;
    }

    private function seedPeriod(array $data): int
    {
        $period = $data['period'];
        DB::table('planning_periods')->updateOrInsert(
            ['starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']],
            [
                'name' => $period['name'],
                'type' => $period['type'] ?? 'month',
                'monthly_norm_minutes' => $period['monthly_norm_minutes'],
                'quarterly_norm_minutes' => $period['quarterly_norm_minutes'],
                'metadata' => json_encode([
                    'demo_scenario' => 'vehicles',
                    'source_checksum' => hash_file('sha256', base_path('sources/vehicles_2026_07.demo.json')),
                    'source' => $data['source_file'],
                    'company_type' => 'transport',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('planning_periods')
            ->where('starts_on', $period['starts_on'])
            ->where('ends_on', $period['ends_on'])
            ->value('id');
    }

    private function seedShifts(array $data): array
    {
        $ids = [];
        foreach ($data['shift_templates'] as $shift) {
            DB::table('shift_templates')->updateOrInsert(
                ['code' => $shift['code']],
                [
                    'name' => $shift['name'],
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                    'duration_minutes' => $shift['duration_minutes'],
                    'crosses_midnight' => $shift['crosses_midnight'],
                    'metadata' => json_encode($shift['metadata'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $ids[$shift['code']] = (int) DB::table('shift_templates')->where('code', $shift['code'])->value('id');
        }

        return $ids;
    }

    private function seedVehicles(array $data, array $skillIds): array
    {
        $sectorNames = collect($data['sectors'])->pluck('name', 'code');
        $sectorOrder = collect($data['sectors'])->pluck('code')->flip();
        $ids = [];

        foreach ($data['vehicles'] as $vehicle) {
            DB::table('planning_units')->updateOrInsert(
                ['code' => $vehicle['code']],
                [
                    'name' => $vehicle['name'],
                    'is_active' => true,
                    'metadata' => json_encode([
                        'demo_scenario' => 'vehicles',
                        'sector_code' => $vehicle['sector_code'],
                        'sector_name' => $sectorNames[$vehicle['sector_code']],
                        'sector_order' => (int) $sectorOrder[$vehicle['sector_code']],
                        'registration' => $vehicle['registration'],
                        'vehicle_type' => $vehicle['vehicle_type'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $unitId = (int) DB::table('planning_units')->where('code', $vehicle['code'])->value('id');
            $ids[$vehicle['code']] = $unitId;

            DB::table('planning_unit_required_skill')->where('planning_unit_id', $unitId)->delete();
            foreach ($vehicle['required_skill_codes'] as $skillCode) {
                DB::table('planning_unit_required_skill')->insert([
                    'planning_unit_id' => $unitId,
                    'skill_id' => $skillIds[$skillCode],
                    'requirement_mode' => 'required',
                ]);
            }
        }

        return $ids;
    }

    private function seedDrivers(array $data, int $groupId, array $skillIds, int $periodId): array
    {
        $period = $data['period'];
        $defaults = $data['resource_limit_defaults'];
        $workingDays = $this->workingDays($period['starts_on'], $period['ends_on']);
        $ids = [];

        foreach ($data['drivers'] as $driver) {
            $workloadPolicy = $driver['workload_policy'] ?? 'must_fill_nominal';
            $employmentFraction = (float) ($driver['employment_fraction'] ?? 1);
            $nominalWorkdayMinutes = (int) ($driver['nominal_workday_minutes'] ?? $defaults['nominal_workday_minutes'] ?? 480);
            $target = $workloadPolicy === 'minimize_usage'
                ? null
                : (int) round($workingDays * $nominalWorkdayMinutes * $employmentFraction);
            $quarterTarget = $workloadPolicy === 'minimize_usage'
                ? null
                : (int) round(((int) $defaults['target_minutes_per_quarter']) * $employmentFraction);

            DB::table('resources')->updateOrInsert(
                ['employee_number' => $driver['employee_number']],
                [
                    'resource_group_id' => $groupId,
                    'external_code' => $driver['external_code'],
                    'name' => $driver['name'],
                    'is_active' => true,
                    'nominal_workday_minutes' => $nominalWorkdayMinutes,
                    'metadata' => json_encode([
                        'demo_scenario' => 'vehicles',
                        'driver_mode' => $driver['driver_mode'],
                        'employment_type' => $driver['employment_type'] ?? 'employment',
                        'employment_fraction' => $workloadPolicy === 'minimize_usage' ? null : $employmentFraction,
                        'workload_policy' => $workloadPolicy,
                        'preferred_max_minutes' => $driver['preferred_max_minutes'] ?? null,
                        'primary_sector_code' => $driver['primary_sector_code'] ?? null,
                        'secondary_sector_codes' => $driver['secondary_sector_codes'] ?? [],
                        'roles' => ['driver'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $resourceId = (int) DB::table('resources')->where('employee_number', $driver['employee_number'])->value('id');
            $ids[$driver['employee_number']] = $resourceId;

            DB::table('resource_skill')->where('resource_id', $resourceId)->delete();
            foreach (array_values(array_unique($driver['skill_codes'] ?? [])) as $skillCode) {
                DB::table('resource_skill')->insert([
                    'resource_id' => $resourceId,
                    'skill_id' => $skillIds[$skillCode],
                    'source' => 'demo',
                ]);
            }

            DB::table('resource_planning_limits')->updateOrInsert(
                ['resource_id' => $resourceId, 'planning_period_id' => $periodId],
                [
                    'max_minutes_per_day' => $defaults['max_minutes_per_day'],
                    'max_minutes_per_month' => $target,
                    'max_minutes_per_quarter' => $quarterTarget,
                    'target_minutes_per_month' => $target,
                    'target_minutes_per_quarter' => $quarterTarget,
                    'min_rest_minutes' => $defaults['min_rest_minutes'],
                    'max_night_shifts_per_month' => null,
                    'metadata' => json_encode(['source' => 'synthetic_demo']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        return $ids;
    }

    private function seedAbsenceTypes(array $data): array
    {
        $ids = [];
        foreach ($data['absence_types'] ?? [] as $type) {
            DB::table('absence_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'blocks_planning' => $type['blocks_planning'],
                    'counts_as_work_time' => $type['counts_as_work_time'],
                    'nominal_minutes_per_day' => $type['nominal_minutes_per_day'] ?? null,
                    'metadata' => json_encode(['demo_scenario' => 'vehicles']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $ids[$type['code']] = (int) DB::table('absence_types')->where('code', $type['code'])->value('id');
        }

        return $ids;
    }

    private function seedAbsences(array $data, array $resourceIds, array $absenceTypeIds, int $periodId): void
    {
        $period = $data['period'];
        $creditedAbsenceMinutes = [];

        foreach ($data['absences'] ?? [] as $absence) {
            $resourceId = $resourceIds[$absence['resource_employee_number']] ?? null;
            $absenceTypeId = $absenceTypeIds[$absence['absence_type']] ?? null;
            if ($resourceId === null || $absenceTypeId === null) {
                continue;
            }

            $minutesPerDay = (int) DB::table('resources')->where('id', $resourceId)->value('nominal_workday_minutes');
            $creditStartsOn = max($absence['start_date'], $period['starts_on']);
            $creditEndsOn = min($absence['end_date'], $period['ends_on']);
            $rawNominalMinutes = $creditStartsOn <= $creditEndsOn
                ? $this->workingDays($creditStartsOn, $creditEndsOn) * $minutesPerDay
                : 0;
            $targetMinutes = DB::table('resource_planning_limits')
                ->where('resource_id', $resourceId)
                ->where('planning_period_id', $periodId)
                ->value('target_minutes_per_month');
            $remainingCredit = $targetMinutes === null
                ? $rawNominalMinutes
                : max(0, (int) $targetMinutes - ($creditedAbsenceMinutes[$resourceId] ?? 0));
            $nominalMinutes = $absence['counts_as_work_time']
                ? min($rawNominalMinutes, $remainingCredit)
                : $rawNominalMinutes;
            if ($absence['counts_as_work_time']) {
                $creditedAbsenceMinutes[$resourceId] = ($creditedAbsenceMinutes[$resourceId] ?? 0) + $nominalMinutes;
            }

            DB::table('absences')->updateOrInsert(
                [
                    'resource_id' => $resourceId,
                    'absence_type_id' => $absenceTypeId,
                    'starts_at' => $absence['start_date'].' 00:00:00',
                    'ends_at' => CarbonImmutable::parse($absence['end_date'])->addDay()->toDateString().' 00:00:00',
                ],
                [
                    'blocks_planning' => $absence['blocks_planning'],
                    'counts_as_work_time' => $absence['counts_as_work_time'],
                    'nominal_minutes' => $nominalMinutes,
                    'source' => 'demo',
                    'metadata' => json_encode(['demo_scenario' => 'vehicles']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedUnitRules(array $data, array $unitIds, array $shiftIds, array $resourceIds): void
    {
        $vehiclesByCode = collect($data['vehicles'])->keyBy('code');
        $driversByNumber = collect($data['drivers'])->keyBy('employee_number');

        foreach ($unitIds as $vehicleCode => $unitId) {
            $sectorCode = $vehiclesByCode[$vehicleCode]['sector_code'];
            foreach ($shiftIds as $shiftId) {
                foreach ($resourceIds as $employeeNumber => $resourceId) {
                    $driver = $driversByNumber[$employeeNumber];
                    $policy = $this->driverPolicy($driver, $sectorCode);
                    DB::table('planning_unit_resource_rules')->updateOrInsert(
                        [
                            'planning_unit_id' => $unitId,
                            'shift_template_id' => $shiftId,
                            'resource_id' => $resourceId,
                        ],
                        [
                            ...$policy,
                            'max_assignments_per_period' => null,
                            'max_minutes_per_period' => null,
                            'requires_manual_approval' => false,
                            'metadata' => json_encode([
                                'demo_scenario' => 'vehicles',
                                'vehicle_code' => $vehicleCode,
                                'sector_code' => $sectorCode,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        }
    }

    private function driverPolicy(array $driver, string $sectorCode): array
    {
        if (($driver['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage') {
            return [
                'usage_mode' => 'fallback',
                'priority' => 900,
                'penalty' => 0,
            ];
        }

        if (($driver['primary_sector_code'] ?? null) === $sectorCode) {
            return ['usage_mode' => 'primary', 'priority' => 100, 'penalty' => 0];
        }

        if (in_array($sectorCode, $driver['secondary_sector_codes'] ?? [], true)) {
            return [
                'usage_mode' => 'secondary',
                'priority' => 500,
                'penalty' => 0,
            ];
        }

        return [
            'usage_mode' => 'excluded',
            'priority' => 1000,
            'penalty' => (int) config('planning.weights.excluded_resource', 100000),
        ];
    }

    private function seedDemandSlots(array $data, int $periodId, array $unitIds, array $shiftIds, array $skillIds): void
    {
        $period = $data['period'];
        foreach ($this->days($period['starts_on'], $period['ends_on']) as $day) {
            foreach ($data['vehicles'] as $vehicle) {
                foreach ($data['shift_templates'] as $shift) {
                    $startsAt = CarbonImmutable::parse($day->toDateString().' '.$shift['start_time']);
                    $endsAt = CarbonImmutable::parse($day->toDateString().' '.$shift['end_time']);
                    if ($shift['crosses_midnight']) {
                        $endsAt = $endsAt->addDay();
                    }

                    DB::table('demand_slots')->updateOrInsert(
                        [
                            'planning_period_id' => $periodId,
                            'planning_unit_id' => $unitIds[$vehicle['code']],
                            'shift_template_id' => $shiftIds[$shift['code']],
                            'starts_at' => $startsAt,
                        ],
                        [
                            'ends_at' => $endsAt,
                            'duration_minutes' => $startsAt->diffInMinutes($endsAt),
                            'required_resources_count' => 1,
                            'priority' => 100,
                            'metadata' => json_encode([
                                'demo_scenario' => 'vehicles',
                                'source' => 'synthetic_demo',
                                'vehicle_code' => $vehicle['code'],
                                'sector_code' => $vehicle['sector_code'],
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );

                    $slotId = (int) DB::table('demand_slots')
                        ->where('planning_period_id', $periodId)
                        ->where('planning_unit_id', $unitIds[$vehicle['code']])
                        ->where('shift_template_id', $shiftIds[$shift['code']])
                        ->where('starts_at', $startsAt)
                        ->value('id');
                    DB::table('demand_slot_required_skill')->where('demand_slot_id', $slotId)->delete();
                    foreach ($vehicle['required_skill_codes'] as $skillCode) {
                        DB::table('demand_slot_required_skill')->insert([
                            'demand_slot_id' => $slotId,
                            'skill_id' => $skillIds[$skillCode],
                            'requirement_mode' => 'required',
                        ]);
                    }
                }
            }
        }
    }

    private function workingDays(string $startsOn, string $endsOn): int
    {
        return count(array_filter(
            $this->days($startsOn, $endsOn),
            fn (CarbonImmutable $day): bool => ! $day->isWeekend(),
        ));
    }

    private function days(string $startsOn, string $endsOn): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse($startsOn); $day <= CarbonImmutable::parse($endsOn); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }
}
