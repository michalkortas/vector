<?php

namespace App\Planning\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PlanningRuleSettings
{
    public static function ensureDefaults(): void
    {
        if (! Schema::hasTable('planning_rule_settings')) {
            return;
        }

        self::renameRuleCode('ward_manager_one_split_per_day', 'flex_resource_one_split_per_day');
        self::renameRuleCode('even_nights', 'shift_balance');

        $profileCodes = self::profileCodes(self::currentScenario());
        foreach (config('planning.rules', []) as $rule) {
            $canToggle = (bool) ($rule['can_toggle'] ?? true);
            $allowedInProfile = $profileCodes === null || in_array($rule['code'], $profileCodes, true);
            $existing = DB::table('planning_rule_settings')->where('code', $rule['code'])->first();
            if ($existing === null) {
                DB::table('planning_rule_settings')->insert([
                    'code' => $rule['code'],
                    'name' => $rule['name'],
                    'type' => 'standard',
                    'is_active' => $allowedInProfile,
                    ...(Schema::hasColumn('planning_rule_settings', 'can_toggle') ? ['can_toggle' => $canToggle] : []),
                    'weight' => $rule['weight'],
                    'metadata' => json_encode(['source' => 'config', ...($rule['metadata'] ?? [])]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $updates = [
                'name' => $rule['name'],
                'type' => 'standard',
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('planning_rule_settings', 'can_toggle')) {
                $updates['can_toggle'] = $canToggle;
            }
            if (! $allowedInProfile) {
                $updates['is_active'] = false;
            } elseif (! $canToggle) {
                $updates['is_active'] = true;
            }
            if ($rule['code'] === 'even_hours' && (int) $existing->weight === 1) {
                $updates['weight'] = $rule['weight'];
            }
            if ($rule['code'] === 'avoid_same_resource_streaks' && (int) $existing->weight === 20000) {
                $updates['weight'] = $rule['weight'];
            }
            if ($rule['code'] === 'contract_usage' && (int) $existing->weight === 500) {
                $updates['weight'] = $rule['weight'];
            }
            if ($rule['code'] === 'shift_balance' && (int) $existing->weight === 500) {
                $updates['weight'] = $rule['weight'];
            }
            $metadata = json_decode($existing->metadata ?? '[]', true) ?: [];
            $defaultMetadata = $rule['metadata'] ?? [];
            foreach ($defaultMetadata as $key => $value) {
                if (! array_key_exists($key, $metadata)) {
                    $metadata[$key] = $value;
                }
            }
            if ($rule['code'] === 'shift_balance') {
                unset($metadata['min_night_share_percent'], $metadata['max_night_share_percent']);
            }
            if ($defaultMetadata !== []) {
                $updates['metadata'] = json_encode($metadata);
            }

            DB::table('planning_rule_settings')->where('code', $rule['code'])->update($updates);
        }
    }

    private static function renameRuleCode(string $from, string $to): void
    {
        $existing = DB::table('planning_rule_settings')->where('code', $from)->first(['id']);
        if ($existing === null) {
            return;
        }

        $targetExists = DB::table('planning_rule_settings')->where('code', $to)->exists();
        if ($targetExists) {
            DB::table('planning_rule_settings')->where('code', $from)->delete();

            return;
        }

        DB::table('planning_rule_settings')
            ->where('code', $from)
            ->update([
                'code' => $to,
                'updated_at' => now(),
            ]);
    }

    public static function resetWeightsToDefaults(): void
    {
        self::ensureDefaults();

        if (! Schema::hasTable('planning_rule_settings')) {
            return;
        }

        foreach (config('planning.rules', []) as $rule) {
            DB::table('planning_rule_settings')
                ->where('code', $rule['code'])
                ->update([
                    'weight' => $rule['weight'],
                    'updated_at' => now(),
                ]);
        }
    }

    public static function applyProfile(?string $scenario): void
    {
        self::ensureDefaults();

        if (! Schema::hasTable('planning_rule_settings')) {
            return;
        }

        $profileCodes = self::profileCodes($scenario);
        foreach (config('planning.rules', []) as $rule) {
            DB::table('planning_rule_settings')
                ->where('code', $rule['code'])
                ->update([
                    'is_active' => $profileCodes === null || in_array($rule['code'], $profileCodes, true),
                    'updated_at' => now(),
                ]);
        }
    }

    public static function all(bool $visibleOnly = true): array
    {
        self::ensureDefaults();
        $configRules = collect(config('planning.rules', []))->keyBy('code');
        $profileCodes = self::profileCodes(self::currentScenario());

        if (! Schema::hasTable('planning_rule_settings')) {
            return collect(config('planning.rules', []))
                ->when($visibleOnly && $profileCodes !== null, fn ($rules) => $rules->whereIn('code', $profileCodes))
                ->map(fn (array $rule): array => [
                    ...$rule,
                    'description' => $rule['description'] ?? '',
                    'type' => 'standard',
                    'is_active' => $profileCodes === null || in_array($rule['code'], $profileCodes, true),
                    'can_toggle' => (bool) ($rule['can_toggle'] ?? true),
                ])->all();
        }

        $hasCanToggle = Schema::hasColumn('planning_rule_settings', 'can_toggle');
        $columns = ['code', 'name', 'type', 'is_active', 'weight'];
        if ($hasCanToggle) {
            $columns[] = 'can_toggle';
        }

        $query = DB::table('planning_rule_settings')->orderBy('id');
        if ($visibleOnly && $profileCodes !== null) {
            $query->whereIn('code', $profileCodes);
        }

        return $query
            ->get($columns)
            ->map(fn ($rule): array => [
                'code' => $rule->code,
                'name' => $rule->name,
                'description' => $configRules->get($rule->code)['description'] ?? '',
                'type' => $rule->type,
                'is_active' => (bool) $rule->is_active,
                'can_toggle' => $hasCanToggle ? (bool) $rule->can_toggle : true,
                'weight' => (int) $rule->weight,
            ])
            ->all();
    }

    public static function applyToConfig(): array
    {
        $settings = collect(self::all(false))->keyBy('code')->all();
        foreach ($settings as $code => $setting) {
            config()->set('planning.weights.'.$code, $setting['weight']);
            if ($code === 'contract_usage') {
                config()->set('planning.weights.contract_usage_per_hour', $setting['weight']);
            }
        }

        return $settings;
    }

    private static function currentScenario(): ?string
    {
        if (! Schema::hasTable('planning_periods')) {
            return null;
        }

        foreach (DB::table('planning_periods')->pluck('metadata') as $metadata) {
            $scenario = json_decode($metadata ?? '[]', true)['demo_scenario'] ?? null;
            if (is_string($scenario) && $scenario !== '') {
                return $scenario;
            }
        }

        return null;
    }

    private static function profileCodes(?string $scenario): ?array
    {
        if ($scenario === null) {
            return null;
        }

        $codes = config('planning.rule_profiles.'.$scenario.'.active_codes');

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : null;
    }
}
