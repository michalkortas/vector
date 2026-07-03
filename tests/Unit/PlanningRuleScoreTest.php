<?php

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Engine\Constraints\MinimumRestConstraint;
use App\Planning\Engine\Repair\DefaultScheduleRepairer;
use App\Planning\Engine\Scoring\ConsecutiveNightShiftScoreRule;
use App\Planning\Engine\Scoring\ContractUsageScoreRule;
use App\Planning\Engine\Scoring\NightRecoveryAfterNightScoreRule;
use App\Planning\Engine\Scoring\NightShiftDistributionScoreRule;
use App\Planning\Engine\Scoring\SameResourceStreakScoreRule;
use App\Planning\Infrastructure\PlanningRuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function planningProblemForRuleScores(array $slots): PlanningProblem
{
    return new PlanningProblem(
        periodId: 1,
        startsOn: CarbonImmutable::parse('2026-07-01'),
        endsOn: CarbonImmutable::parse('2026-07-31'),
        monthlyNormMinutes: 10465,
        quarterlyNormMinutes: 29570,
        resources: [1 => ['id' => 1, 'metadata' => []]],
        skillsByResource: [],
        planningUnits: [],
        demandSlots: $slots,
        requiredSkillsBySlot: [],
        absences: [],
        availabilityRules: [],
        holidays: [],
        limitsByResource: [],
        unitRules: [],
    );
}

it('penalizes work on the day after a night shift', function (): void {
    config()->set('planning.weights.night_recovery_after_night', 12000);

    $problem = planningProblemForRuleScores([
        1 => ['id' => 1, 'starts_at' => '2026-07-01 19:00:00', 'ends_at' => '2026-07-02 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
        2 => ['id' => 2, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
    ]);

    $component = (new NightRecoveryAfterNightScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1]));

    expect($component->code)->toBe('night_recovery_after_night')
        ->and($component->score)->toBe(12000)
        ->and($component->metadata['count'])->toBe(1);
});

it('penalizes consecutive night shifts', function (): void {
    config()->set('planning.weights.consecutive_nights', 20000);

    $problem = planningProblemForRuleScores([
        1 => ['id' => 1, 'starts_at' => '2026-07-01 19:00:00', 'ends_at' => '2026-07-02 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
        2 => ['id' => 2, 'starts_at' => '2026-07-02 19:00:00', 'ends_at' => '2026-07-03 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
    ]);

    $component = (new ConsecutiveNightShiftScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1]));

    expect($component->code)->toBe('consecutive_nights')
        ->and($component->score)->toBe(20000)
        ->and($component->metadata['count'])->toBe(1);
});

it('penalizes contract usage over the preferred max from source data', function (): void {
    config()->set('planning.weights.contract_usage_per_hour', 100);

    $problem = new PlanningProblem(
        periodId: 1,
        startsOn: CarbonImmutable::parse('2026-07-01'),
        endsOn: CarbonImmutable::parse('2026-07-31'),
        monthlyNormMinutes: 10465,
        quarterlyNormMinutes: 29570,
        resources: [
            17 => ['id' => 17, 'metadata' => ['workload_policy' => 'minimize_usage', 'preferred_max_minutes' => 720]],
            20 => ['id' => 20, 'metadata' => ['workload_policy' => 'minimize_usage', 'preferred_max_minutes' => 720]],
        ],
        skillsByResource: [],
        planningUnits: [],
        demandSlots: [
            1 => ['id' => 1, 'starts_at' => '2026-07-01 07:00:00', 'ends_at' => '2026-07-01 19:00:00', 'duration_minutes' => 720],
            2 => ['id' => 2, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720],
        ],
        requiredSkillsBySlot: [],
        absences: [],
        availabilityRules: [],
        holidays: [],
        limitsByResource: [],
        unitRules: [],
    );

    $component = (new ContractUsageScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 17, '2:1' => 17]));

    expect($component->metadata['minutes'])->toBe(1440)
        ->and($component->metadata['over_preferred_minutes'])->toBe(720)
        ->and($component->metadata['missing_minimum_resource_ids'])->toBe([20])
        ->and($component->metadata['distribution_penalty_hours'])->toBe(24)
        ->and($component->score)->toBe(1200 * 2 + 1200 * 8 + 7200 + 2400);
});

it('penalizes schedules where one employee has only night shifts', function (): void {
    config()->set('planning.weights.even_nights', 10);

    $problem = new PlanningProblem(
        periodId: 1,
        startsOn: CarbonImmutable::parse('2026-07-01'),
        endsOn: CarbonImmutable::parse('2026-07-31'),
        monthlyNormMinutes: 10465,
        quarterlyNormMinutes: 29570,
        resources: [
            1 => ['id' => 1, 'metadata' => []],
            2 => ['id' => 2, 'metadata' => []],
        ],
        skillsByResource: [],
        planningUnits: [],
        demandSlots: [
            1 => ['id' => 1, 'starts_at' => '2026-07-01 19:00:00', 'ends_at' => '2026-07-02 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
            2 => ['id' => 2, 'starts_at' => '2026-07-03 19:00:00', 'ends_at' => '2026-07-04 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
            3 => ['id' => 3, 'starts_at' => '2026-07-05 19:00:00', 'ends_at' => '2026-07-06 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
            4 => ['id' => 4, 'starts_at' => '2026-07-07 07:00:00', 'ends_at' => '2026-07-07 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        ],
        requiredSkillsBySlot: [],
        absences: [],
        availabilityRules: [],
        holidays: [],
        limitsByResource: [],
        unitRules: [],
    );

    $component = (new NightShiftDistributionScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1, '3:1' => 1, '4:1' => 2]));

    expect($component->metadata['night_share_penalty'])->toBe(40000)
        ->and($component->metadata['night_counts'][1])->toBe(3)
        ->and($component->metadata['assignment_counts'][1])->toBe(3)
        ->and($component->score)->toBeGreaterThan(400000);
});

it('penalizes repeated assignments of the same resource in one schedule row', function (): void {
    config()->set('planning.weights.avoid_same_resource_streaks', 20000);

    $problem = planningProblemForRuleScores([
        1 => ['id' => 1, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-01 07:00:00', 'ends_at' => '2026-07-01 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        2 => ['id' => 2, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        3 => ['id' => 3, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-05 07:00:00', 'ends_at' => '2026-07-05 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
    ]);

    $component = (new SameResourceStreakScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1, '3:1' => 1]));

    expect($component->code)->toBe('avoid_same_resource_streaks')
        ->and($component->score)->toBe(20000)
        ->and($component->metadata['count'])->toBe(1);
});

it('penalizes long repeated assignment streaks non-linearly', function (): void {
    config()->set('planning.weights.avoid_same_resource_streaks', 100);

    $problem = planningProblemForRuleScores([
        1 => ['id' => 1, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-01 07:00:00', 'ends_at' => '2026-07-01 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        2 => ['id' => 2, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        3 => ['id' => 3, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-03 07:00:00', 'ends_at' => '2026-07-03 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        4 => ['id' => 4, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-04 07:00:00', 'ends_at' => '2026-07-04 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
    ]);

    $component = (new SameResourceStreakScoreRule)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1, '3:1' => 1, '4:1' => 1]));

    expect($component->score)->toBe(1400)
        ->and($component->metadata['count'])->toBe(14)
        ->and($component->metadata['longest_streak'])->toBe(4);
});

it('repairs avoidable same row assignment streaks', function (): void {
    $problem = new PlanningProblem(
        periodId: 1,
        startsOn: CarbonImmutable::parse('2026-07-01'),
        endsOn: CarbonImmutable::parse('2026-07-31'),
        monthlyNormMinutes: 10465,
        quarterlyNormMinutes: 29570,
        resources: [1 => ['id' => 1, 'is_active' => true, 'metadata' => []], 2 => ['id' => 2, 'is_active' => true, 'metadata' => []]],
        skillsByResource: [],
        planningUnits: [],
        demandSlots: [
            1 => ['id' => 1, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-01 07:00:00', 'ends_at' => '2026-07-01 19:00:00', 'duration_minutes' => 720, 'required_resources_count' => 1],
            2 => ['id' => 2, 'planning_unit_id' => 10, 'shift_template_id' => 20, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720, 'required_resources_count' => 1],
        ],
        requiredSkillsBySlot: [],
        absences: [],
        availabilityRules: [],
        holidays: [],
        limitsByResource: [],
        unitRules: [],
    );
    $pool = new CandidatePool([
        '1:1' => [['resource_id' => 1, 'usage_mode' => 'primary', 'penalty' => 0, 'priority' => 1], ['resource_id' => 2, 'usage_mode' => 'primary', 'penalty' => 0, 'priority' => 2]],
        '2:1' => [['resource_id' => 1, 'usage_mode' => 'primary', 'penalty' => 0, 'priority' => 1], ['resource_id' => 2, 'usage_mode' => 'primary', 'penalty' => 0, 'priority' => 2]],
    ]);

    $repaired = (new DefaultScheduleRepairer)->repair($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1]), $pool);

    expect($repaired->genes['1:1'])->toBe(1)
        ->and($repaired->genes['2:1'])->toBe(2);
});

it('treats a day shift immediately after a night shift as a hard minimum rest violation', function (): void {
    $problem = new PlanningProblem(
        periodId: 1,
        startsOn: CarbonImmutable::parse('2026-07-01'),
        endsOn: CarbonImmutable::parse('2026-07-31'),
        monthlyNormMinutes: 10465,
        quarterlyNormMinutes: 29570,
        resources: [1 => ['id' => 1, 'metadata' => []]],
        skillsByResource: [],
        planningUnits: [],
        demandSlots: [
            1 => ['id' => 1, 'starts_at' => '2026-07-01 19:00:00', 'ends_at' => '2026-07-02 07:00:00', 'duration_minutes' => 720, 'shift_code' => 'NIGHT_12H'],
            2 => ['id' => 2, 'starts_at' => '2026-07-02 07:00:00', 'ends_at' => '2026-07-02 19:00:00', 'duration_minutes' => 720, 'shift_code' => 'DAY_12H'],
        ],
        requiredSkillsBySlot: [],
        absences: [],
        availabilityRules: [],
        holidays: [],
        limitsByResource: [1 => ['min_rest_minutes' => 660]],
        unitRules: [],
    );

    $violations = (new MinimumRestConstraint)->evaluate($problem, new ScheduleChromosome(['1:1' => 1, '2:1' => 1]));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->code)->toBe('min_rest_violation')
        ->and($violations[0]->metadata['rest_minutes'])->toBe(0)
        ->and($violations[0]->metadata['required_minutes'])->toBe(660);
});

it('does not overwrite edited planning rule settings when defaults are ensured again', function (): void {
    PlanningRuleSettings::ensureDefaults();

    DB::table('planning_rule_settings')->where('code', 'consecutive_nights')->update([
        'is_active' => false,
        'weight' => 123,
        'updated_at' => now(),
    ]);

    PlanningRuleSettings::ensureDefaults();

    $rule = DB::table('planning_rule_settings')->where('code', 'consecutive_nights')->first();

    expect((bool) $rule->is_active)->toBeFalse()
        ->and((int) $rule->weight)->toBe(123)
        ->and($rule->type)->toBe('standard');
});

it('keeps minimum rest active and non-toggleable', function (): void {
    PlanningRuleSettings::ensureDefaults();

    DB::table('planning_rule_settings')->where('code', 'min_rest_violation')->update([
        'is_active' => false,
        'updated_at' => now(),
    ]);

    PlanningRuleSettings::ensureDefaults();

    $rule = DB::table('planning_rule_settings')->where('code', 'min_rest_violation')->first();

    expect((bool) $rule->is_active)->toBeTrue()
        ->and((bool) $rule->can_toggle)->toBeFalse();
});
