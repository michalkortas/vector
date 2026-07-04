<?php

namespace Database\Seeders;

use App\Planning\Infrastructure\PlanningRuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoScheduleFromImageSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(base_path('sources/grafik_2026_07.extracted.json')), true, flags: JSON_THROW_ON_ERROR);
        PlanningRuleSettings::resetWeightsToDefaults();

        DB::transaction(function () use ($data): void {
            $groupId = DB::table('resource_groups')->updateOrInsert(
                ['code' => 'medical_staff'],
                ['name' => 'Personel medyczny', 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            );
            $groupId = (int) DB::table('resource_groups')->where('code', 'medical_staff')->value('id');

            $skillIds = $this->seedSkills($data);
            DB::table('resource_group_skill')->updateOrInsert(['resource_group_id' => $groupId, 'skill_id' => $skillIds['medical_staffing']]);

            $periodId = $this->seedPeriod($data);
            $unitIds = $this->seedUnits($data, $skillIds['medical_staffing']);
            $shiftIds = $this->seedShifts($data);
            $resourceIds = $this->seedResources($data, $groupId, $skillIds, $periodId);
            $absenceTypeIds = $this->seedAbsenceTypes($data);
            $this->seedAbsences($data, $resourceIds, $absenceTypeIds);
            $this->seedAvailabilityRules($data, $resourceIds);
            $this->seedHolidays($data, $resourceIds);
            $this->seedUnitRules($data, $unitIds, $shiftIds, $resourceIds);
            $this->seedSubstitutionPolicies($data, $unitIds, $shiftIds, $resourceIds);
            $this->seedDemandSlots($data, $periodId, $unitIds, $shiftIds, $skillIds);
        });
    }

    private function seedSkills(array $data): array
    {
        $ids = [];
        foreach ($data['skills'] as $skill) {
            DB::table('skills')->updateOrInsert(
                ['code' => $skill['code']],
                ['name' => $skill['name'], 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            );
            $ids[$skill['code']] = (int) DB::table('skills')->where('code', $skill['code'])->value('id');
        }

        return $ids;
    }

    private function seedPeriod(array $data): int
    {
        DB::table('planning_periods')->updateOrInsert(
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
            [
                'name' => 'Lipiec 2026',
                'type' => 'month',
                'monthly_norm_minutes' => $data['monthly_norm_minutes'],
                'quarterly_norm_minutes' => $data['quarterly_norm_minutes'],
                'metadata' => json_encode(['department' => $data['department'], 'source' => $data['source_file']]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    }

    private function seedUnits(array $data, int $skillId): array
    {
        $ids = [];
        foreach ($data['planning_units'] as $unit) {
            DB::table('planning_units')->updateOrInsert(
                ['code' => $unit['code']],
                ['name' => $unit['name'], 'is_active' => true, 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            );
            $id = (int) DB::table('planning_units')->where('code', $unit['code'])->value('id');
            $ids[$unit['code']] = $id;
            DB::table('planning_unit_required_skill')->updateOrInsert(['planning_unit_id' => $id, 'skill_id' => $skillId], ['requirement_mode' => 'required']);
        }

        return $ids;
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
                    'metadata' => json_encode($this->shiftMetadata($shift)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $ids[$shift['code']] = (int) DB::table('shift_templates')->where('code', $shift['code'])->value('id');
        }

        return $ids;
    }

    private function shiftMetadata(array $shift): array
    {
        $metadata = $shift['metadata'] ?? [];
        if (isset($metadata['balance_group'])) {
            return $metadata;
        }

        return [
            ...$metadata,
            'balance_group' => $shift['crosses_midnight'] ? 'night' : ($shift['duration_minutes'] >= 720 ? 'day' : 'short_day'),
        ];
    }

    private function seedResources(array $data, int $groupId, array $skillIds, int $periodId): array
    {
        $ids = [];
        foreach ($data['employees'] as $employee) {
            $plannedDutiesExpression = $this->plannedDutiesExpression($employee);
            $plannedDutiesMinutes = $this->parseDurationExpressionMinutes($plannedDutiesExpression);
            $workloadPolicy = $employee['workload_policy'] ?? 'must_fill_nominal';
            $employmentFraction = (float) ($employee['employment_fraction'] ?? 1);
            $policyGroupCode = $data['planning_unit_resource_policy']['resource_policy_group_codes_by_employee_number'][(string) $employee['employee_number']]
                ?? $employee['resource_policy_group_code']
                ?? null;
            DB::table('resources')->updateOrInsert(
                ['employee_number' => $employee['employee_number']],
                [
                    'resource_group_id' => $groupId,
                    'external_code' => 'EMP-'.$employee['employee_number'],
                    'name' => $employee['name'],
                    'is_active' => ! in_array($plannedDutiesExpression, ['-'], true),
                    'metadata' => json_encode([
                        'planned_duties_expression_raw' => $plannedDutiesExpression,
                        'planned_duties_minutes' => $plannedDutiesMinutes,
                        'preferred_max_minutes' => $workloadPolicy === 'minimize_usage' ? $plannedDutiesMinutes : null,
                        'note' => $employee['note'] ?? null,
                        'roles' => $employee['roles'] ?? [],
                        'employment_type' => $employee['employment_type'] ?? 'employment',
                        'employment_fraction' => $workloadPolicy === 'minimize_usage' ? null : $employmentFraction,
                        'workload_policy' => $workloadPolicy,
                        'policy_group_code' => $policyGroupCode,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $id = (int) DB::table('resources')->where('employee_number', $employee['employee_number'])->value('id');
            $ids[$employee['employee_number']] = $id;
            DB::table('resource_skill')->where('resource_id', $id)->delete();
            foreach ($employee['skill_codes'] ?? ['medical_staffing'] as $skillCode) {
                DB::table('resource_skill')->updateOrInsert(['resource_id' => $id, 'skill_id' => $skillIds[$skillCode]], ['source' => 'imported']);
            }

            $target = $workloadPolicy === 'minimize_usage'
                ? null
                : $this->fractionalMinutes((int) $data['monthly_norm_minutes'], $employmentFraction);
            $quarterTarget = $workloadPolicy === 'minimize_usage'
                ? null
                : $this->fractionalMinutes((int) $data['resource_limit_defaults']['target_minutes_per_quarter'], $employmentFraction);
            DB::table('resource_planning_limits')->updateOrInsert(
                ['resource_id' => $id, 'planning_period_id' => $periodId],
                [
                    'max_minutes_per_day' => $data['resource_limit_defaults']['max_minutes_per_day'],
                    'max_minutes_per_month' => $workloadPolicy === 'minimize_usage' ? null : $target,
                    'max_minutes_per_quarter' => $quarterTarget,
                    'target_minutes_per_month' => $target,
                    'target_minutes_per_quarter' => $quarterTarget,
                    'min_rest_minutes' => $data['resource_limit_defaults']['min_rest_minutes'],
                    'max_night_shifts_per_month' => null,
                    'metadata' => json_encode(['source' => 'image']),
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
        foreach ($data['absence_types'] as $type) {
            DB::table('absence_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'blocks_planning' => $type['blocks_planning'],
                    'counts_as_work_time' => $type['counts_as_work_time'],
                    'nominal_minutes_per_day' => $type['nominal_minutes_per_day'],
                    'metadata' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $ids[$type['code']] = (int) DB::table('absence_types')->where('code', $type['code'])->value('id');
        }

        return $ids;
    }

    private function seedAbsences(array $data, array $resourceIds, array $absenceTypeIds): void
    {
        $creditedAbsenceMinutes = [];
        foreach ($data['absences'] as $absence) {
            $resourceId = $resourceIds[$absence['resource_employee_number']] ?? null;
            if (! $resourceId) {
                continue;
            }
            $employee = collect($data['employees'])->firstWhere('employee_number', $absence['resource_employee_number']);
            $targetMinutes = (($employee['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage')
                ? null
                : $this->fractionalMinutes((int) $data['monthly_norm_minutes'], (float) ($employee['employment_fraction'] ?? 1));
            $rawNominalMinutes = $this->paidAbsenceMinutes($absence['start_date'], $absence['end_date'], (int) ($data['resource_limit_defaults']['nominal_minutes_per_absence_day'] ?? 480));
            $remainingCredit = $targetMinutes === null ? $rawNominalMinutes : max(0, $targetMinutes - ($creditedAbsenceMinutes[$resourceId] ?? 0));
            $nominalMinutes = min($rawNominalMinutes, $remainingCredit);
            $creditedAbsenceMinutes[$resourceId] = ($creditedAbsenceMinutes[$resourceId] ?? 0) + $nominalMinutes;
            DB::table('absences')->updateOrInsert(
                [
                    'resource_id' => $resourceId,
                    'absence_type_id' => $absenceTypeIds[$absence['absence_type']],
                    'starts_at' => $absence['start_date'].' 00:00:00',
                    'ends_at' => CarbonImmutable::parse($absence['end_date'])->addDay()->toDateString().' 00:00:00',
                ],
                [
                    'blocks_planning' => $absence['blocks_planning'],
                    'counts_as_work_time' => $absence['counts_as_work_time'],
                    'nominal_minutes' => $nominalMinutes,
                    'source' => 'image',
                    'metadata' => json_encode(['uncertain' => $absence['uncertain'] ?? false, 'note' => $absence['note'] ?? null]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function paidAbsenceMinutes(string $startDate, string $endDate, int $minutesPerDay): int
    {
        $minutes = 0;
        for ($day = CarbonImmutable::parse($startDate); $day <= CarbonImmutable::parse($endDate); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $minutes += $minutesPerDay;
            }
        }

        return $minutes;
    }

    private function seedAvailabilityRules(array $data, array $resourceIds): void
    {
        foreach ($data['availability_rules'] ?? [] as $rule) {
            $resourceId = $resourceIds[$rule['resource_employee_number']] ?? null;
            if ($resourceId === null) {
                continue;
            }

            DB::table('availability_rules')->updateOrInsert(
                [
                    'resource_id' => $resourceId,
                    'name' => $rule['name'],
                    'rule_type' => $rule['rule_type'],
                    'day_of_week' => $rule['day_of_week'] ?? null,
                ],
                [
                    'start_time' => $rule['start_time'] ?? null,
                    'end_time' => $rule['end_time'] ?? null,
                    'effective_from' => $rule['effective_from'] ?? null,
                    'effective_to' => $rule['effective_to'] ?? null,
                    'priority' => $rule['priority'] ?? 100,
                    'metadata' => json_encode(['source' => 'json', ...($rule['metadata'] ?? [])]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedHolidays(array $data, array $resourceIds): void
    {
        foreach ($data['holidays'] ?? [] as $holiday) {
            $scope = $holiday['scope'] ?? 'global';
            $resourceId = null;
            $resourceGroupId = null;

            if ($scope === 'resource') {
                $resourceId = $resourceIds[$holiday['resource_employee_number']] ?? null;
                if ($resourceId === null) {
                    continue;
                }
            }

            if ($scope === 'resource_group') {
                $resourceGroupId = DB::table('resource_groups')->where('code', $holiday['resource_group_code'])->value('id');
                if ($resourceGroupId === null) {
                    continue;
                }
            }

            DB::table('calendar_holidays')->updateOrInsert(
                [
                    'holiday_date' => $holiday['date'],
                    'scope' => $scope,
                    'resource_id' => $resourceId,
                    'resource_group_id' => $resourceGroupId,
                ],
                [
                    'name' => $holiday['name'],
                    'blocks_planning' => $holiday['blocks_planning'] ?? true,
                    'metadata' => json_encode(['source' => $holiday['source'] ?? 'json']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedUnitRules(array $data, array $unitIds, array $shiftIds, array $resourceIds): void
    {
        DB::table('planning_unit_resource_rules')->delete();

        $policy = $data['planning_unit_resource_policy'];
        $fallbackEmployees = $policy['fallback_employee_numbers'] ?? [];
        $primaryUnitsByEmployee = $policy['primary_unit_codes_by_employee_number'] ?? [];
        $primaryShiftsByEmployee = $policy['primary_shift_codes_by_employee_number'] ?? [];
        $ruleSlots = collect($data['demand_generation'])
            ->map(fn (array $definition): array => [
                'unit_code' => $definition['planning_unit_code'],
                'shift_code' => $definition['shift_code'],
            ])
            ->unique(fn (array $definition): string => $definition['unit_code'].':'.$definition['shift_code'])
            ->values();

        foreach ($ruleSlots as $ruleSlot) {
            $unitCode = $ruleSlot['unit_code'];
            $shiftCode = $ruleSlot['shift_code'];
            $unitId = $unitIds[$unitCode];
            $shiftId = $shiftIds[$shiftCode];
            foreach ($resourceIds as $employeeNumber => $resourceId) {
                $employee = collect($data['employees'])->firstWhere('employee_number', $employeeNumber);
                $resourcePolicy = $this->resourcePolicyForSlot(
                    $policy,
                    (int) $employeeNumber,
                    $employee,
                    $unitCode,
                    $shiftCode,
                    $fallbackEmployees,
                    $primaryUnitsByEmployee,
                    $primaryShiftsByEmployee,
                );
                DB::table('planning_unit_resource_rules')->insert([
                    'planning_unit_id' => $unitId,
                    'shift_template_id' => $shiftId,
                    'resource_id' => $resourceId,
                    'usage_mode' => $resourcePolicy['usage_mode'],
                    'priority' => $resourcePolicy['priority'],
                    'penalty' => $resourcePolicy['penalty'],
                    'max_assignments_per_period' => $resourcePolicy['max_assignments_per_period'],
                    'metadata' => json_encode([
                        'source' => 'demo',
                        'unit_code' => $unitCode,
                        'shift_code' => $shiftCode,
                        ...$resourcePolicy['metadata'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function resourcePolicyForSlot(array $policy, int $employeeNumber, ?array $employee, string $unitCode, string $shiftCode, array $fallbackEmployees, array $primaryUnitsByEmployee, array $primaryShiftsByEmployee): array
    {
        $unitGroupCode = $policy['planning_unit_group_codes_by_unit_code'][$unitCode] ?? $unitCode;
        $policyGroupCode = $policy['resource_policy_group_codes_by_employee_number'][(string) $employeeNumber]
            ?? $employee['resource_policy_group_code']
            ?? null;
        $policyGroup = $policyGroupCode ? ($policy['resource_policy_groups'][$policyGroupCode] ?? null) : null;

        if (is_array($policyGroup)) {
            foreach ($policyGroup['rules'] ?? [] as $rule) {
                if (! $this->policyRuleMatchesSlot($rule, $unitCode, $unitGroupCode, $shiftCode)) {
                    continue;
                }

                return $this->policyRuleDefaults(
                    $rule['usage_mode'] ?? ($policyGroup['default_usage_mode'] ?? 'primary'),
                    $policy,
                    [
                        'priority' => $rule['priority'] ?? null,
                        'penalty' => $rule['penalty'] ?? null,
                        'max_assignments_per_period' => $rule['max_assignments_per_period'] ?? null,
                        'metadata' => [
                            'policy_group_code' => $policyGroupCode,
                            'planning_unit_group_code' => $unitGroupCode,
                            'policy_source' => 'resource_policy_group_rule',
                        ],
                    ],
                );
            }

            return $this->policyRuleDefaults(
                $policyGroup['default_usage_mode'] ?? 'primary',
                $policy,
                [
                    'priority' => $policyGroup['default_priority'] ?? null,
                    'penalty' => $policyGroup['default_penalty'] ?? null,
                    'max_assignments_per_period' => $policyGroup['default_max_assignments_per_period'] ?? null,
                    'metadata' => [
                        'policy_group_code' => $policyGroupCode,
                        'planning_unit_group_code' => $unitGroupCode,
                        'policy_source' => 'resource_policy_group_default',
                    ],
                ],
            );
        }

        $primaryUnits = $primaryUnitsByEmployee[(string) $employeeNumber] ?? [];
        $primaryShifts = $primaryShiftsByEmployee[(string) $employeeNumber] ?? null;
        $isFallbackEmployee = in_array($employeeNumber, $fallbackEmployees, true) || (($employee['workload_policy'] ?? 'must_fill_nominal') === 'minimize_usage');
        $isPrimarySlot = in_array($unitCode, $primaryUnits, true) && ($primaryShifts === null || in_array($shiftCode, $primaryShifts, true));
        $usageMode = $isFallbackEmployee && ! $isPrimarySlot ? 'fallback' : 'primary';

        return $this->policyRuleDefaults($usageMode, $policy, [
            'metadata' => [
                'planning_unit_group_code' => $unitGroupCode,
                'policy_source' => 'legacy_employee_policy',
            ],
        ]);
    }

    private function policyRuleMatchesSlot(array $rule, string $unitCode, string $unitGroupCode, string $shiftCode): bool
    {
        $unitCodes = $rule['planning_unit_codes'] ?? null;
        if (is_array($unitCodes) && ! in_array($unitCode, $unitCodes, true)) {
            return false;
        }

        $unitGroupCodes = $rule['planning_unit_group_codes'] ?? null;
        if (is_array($unitGroupCodes) && ! in_array($unitGroupCode, $unitGroupCodes, true)) {
            return false;
        }

        $shiftCodes = $rule['shift_codes'] ?? null;
        if (is_array($shiftCodes) && ! in_array($shiftCode, $shiftCodes, true)) {
            return false;
        }

        return true;
    }

    private function policyRuleDefaults(string $usageMode, array $policy, array $overrides = []): array
    {
        $defaults = match ($usageMode) {
            'excluded' => [
                'usage_mode' => 'excluded',
                'priority' => 1000,
                'penalty' => config('planning.weights.excluded_resource', 100000),
                'max_assignments_per_period' => null,
            ],
            'fallback', 'emergency_only' => [
                'usage_mode' => $usageMode,
                'priority' => 900,
                'penalty' => (int) ($policy['fallback_penalty'] ?? config('planning.weights.fallback_usage', 1500)),
                'max_assignments_per_period' => $policy['fallback_max_assignments_per_period'] ?? null,
            ],
            'secondary' => [
                'usage_mode' => 'secondary',
                'priority' => 500,
                'penalty' => (int) ($policy['secondary_penalty'] ?? config('planning.weights.secondary_usage', 300)),
                'max_assignments_per_period' => null,
            ],
            default => [
                'usage_mode' => 'primary',
                'priority' => 100,
                'penalty' => 0,
                'max_assignments_per_period' => null,
            ],
        };

        foreach (['priority', 'penalty', 'max_assignments_per_period'] as $key) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $defaults[$key] = $overrides[$key];
            }
        }
        $defaults['metadata'] = $overrides['metadata'] ?? [];

        return $defaults;
    }

    private function seedSubstitutionPolicies(array $data, array $unitIds, array $shiftIds, array $resourceIds): void
    {
        DB::table('resource_substitution_policies')->delete();
        foreach ($data['resource_substitution_policies'] ?? [] as $policy) {
            DB::table('resource_substitution_policies')->insert([
                'resource_id' => $resourceIds[$policy['resource_employee_number']],
                'primary_planning_unit_id' => $unitIds[$policy['primary_planning_unit_code']],
                'primary_shift_template_id' => $shiftIds[$policy['primary_shift_code']] ?? null,
                'when_used_as_usage_mode' => $policy['when_used_as_usage_mode'],
                'effect' => $policy['effect'],
                'metadata' => json_encode(['source' => 'image', ...($policy['metadata'] ?? [])]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedDemandSlots(array $data, int $periodId, array $unitIds, array $shiftIds, array $skillIds): void
    {
        for ($day = CarbonImmutable::parse('2026-07-01'); $day <= CarbonImmutable::parse('2026-07-31'); $day = $day->addDay()) {
            foreach ($data['demand_generation'] as $definition) {
                if (($definition['applies_to'] ?? 'all_days') === 'weekdays' && $day->isWeekend()) {
                    continue;
                }
                $shift = collect($data['shift_templates'])->firstWhere('code', $definition['shift_code']);
                $metadata = ['source' => 'demo'];
                if (($definition['senior_required_count'] ?? 0) > 0) {
                    $metadata['senior_coverage_group'] = $day->toDateString().':'.$definition['shift_code'];
                    $metadata['senior_required_count'] = (int) $definition['senior_required_count'];
                    $metadata['senior_skill_ids'] = array_map(fn (string $skillCode): int => $skillIds[$skillCode], $definition['senior_skill_codes'] ?? []);
                }
                $this->slot(
                    $periodId,
                    $unitIds[$definition['planning_unit_code']],
                    $shiftIds[$definition['shift_code']],
                    array_map(fn (string $skillCode): int => $skillIds[$skillCode], $definition['required_skill_codes']),
                    $day,
                    $shift['start_time'],
                    $shift['end_time'],
                    $shift['crosses_midnight'],
                    $definition['required_resources_count'],
                    $metadata,
                );
            }
        }
    }

    private function slot(int $periodId, int $unitId, int $shiftId, array $skillIds, CarbonImmutable $day, string $start, string $end, bool $crossesMidnight = false, int $requiredResourcesCount = 1, array $metadata = []): void
    {
        $startsAt = CarbonImmutable::parse($day->toDateString().' '.$start);
        $endsAt = CarbonImmutable::parse($day->toDateString().' '.$end);
        if ($crossesMidnight) {
            $endsAt = $endsAt->addDay();
        }
        DB::table('demand_slots')->updateOrInsert([
            'planning_period_id' => $periodId,
            'planning_unit_id' => $unitId,
            'shift_template_id' => $shiftId,
            'starts_at' => $startsAt,
        ], [
            'ends_at' => $endsAt,
            'duration_minutes' => $startsAt->diffInMinutes($endsAt),
            'required_resources_count' => $requiredResourcesCount,
            'priority' => 100,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slotId = (int) DB::table('demand_slots')
            ->where('planning_period_id', $periodId)
            ->where('planning_unit_id', $unitId)
            ->where('shift_template_id', $shiftId)
            ->where('starts_at', $startsAt)
            ->value('id');

        DB::table('demand_slot_required_skill')->where('demand_slot_id', $slotId)->delete();
        foreach ($skillIds as $skillId) {
            DB::table('demand_slot_required_skill')->updateOrInsert(['demand_slot_id' => $slotId, 'skill_id' => $skillId], ['requirement_mode' => 'required']);
        }
    }

    private function plannedDutiesExpression(array $employee): string
    {
        return $employee['planned_duties_expression_raw'] ?? $employee['target_expression_raw'] ?? '-';
    }

    private function fractionalMinutes(int $minutes, float $fraction): int
    {
        return (int) round($minutes * $fraction);
    }

    private function parseDurationExpressionMinutes(string $raw): ?int
    {
        if ($raw === '-') {
            return null;
        }
        $total = 0;
        foreach (explode('+', $raw) as $part) {
            if (str_contains($part, 'x')) {
                [$count, $hours] = explode('x', $part, 2);
                $total += ((int) $count) * $this->clockHoursToMinutes($hours);
            } elseif ($part !== '') {
                $total += $this->clockHoursToMinutes($part);
            }
        }

        return $total > 0 ? $total : null;
    }

    private function clockHoursToMinutes(string $value): int
    {
        if (str_contains($value, ',')) {
            [$hours, $minutes] = explode(',', $value, 2);

            return ((int) $hours) * 60 + (int) str_pad($minutes, 2, '0');
        }

        return ((int) $value) * 60;
    }
}
