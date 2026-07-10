<?php

namespace App\Planning\Jobs;

use Database\Seeders\DemoScheduleFromImageSeeder;
use Database\Seeders\VehiclesDemoSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class SeedDemoData
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public const MEDICAL = 'medical';

    public const VEHICLES = 'vehicles';

    public function __construct(public readonly string $scenario = self::MEDICAL) {}

    public function handle(): void
    {
        if (! in_array($this->scenario, [self::MEDICAL, self::VEHICLES], true)) {
            throw new InvalidArgumentException("Unsupported demo data scenario [{$this->scenario}]. Expected medical or vehicles.");
        }

        DB::transaction(function (): void {
            $currentScenario = $this->currentScenario();
            if ($currentScenario !== null && ! in_array($currentScenario, [self::MEDICAL, self::VEHICLES], true)) {
                throw new InvalidArgumentException("Refusing to replace unrecognized planning data [{$currentScenario}] with demo scenario [{$this->scenario}].");
            }

            $currentChecksum = $this->currentSourceChecksum();
            $sourceChanged = $currentScenario === $this->scenario
                && $currentChecksum !== null
                && ! hash_equals($currentChecksum, $this->requestedSourceChecksum());

            if (($currentScenario !== null && $currentScenario !== $this->scenario) || $sourceChanged) {
                $this->clearPlanningData();
            }

            $seeder = match ($this->scenario) {
                self::MEDICAL => app(DemoScheduleFromImageSeeder::class),
                self::VEHICLES => app(VehiclesDemoSeeder::class),
            };

            $seeder->run();
        });
    }

    private function currentSourceChecksum(): ?string
    {
        return DB::table('planning_periods')
            ->pluck('metadata')
            ->map(fn (?string $metadata): ?string => json_decode($metadata ?? '[]', true)['source_checksum'] ?? null)
            ->filter()
            ->first();
    }

    private function requestedSourceChecksum(): string
    {
        $path = match ($this->scenario) {
            self::MEDICAL => base_path('sources/grafik_2026_07.extracted.json'),
            self::VEHICLES => base_path('sources/vehicles_2026_07.demo.json'),
        };
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException("Unable to calculate demo source checksum for [{$path}].");
        }

        return $checksum;
    }

    private function currentScenario(): ?string
    {
        $scenarios = DB::table('planning_periods')
            ->pluck('metadata')
            ->map(fn (?string $metadata): ?string => json_decode($metadata ?? '[]', true)['demo_scenario'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($scenarios->count() === 1) {
            return (string) $scenarios->first();
        }

        if ($scenarios->count() > 1) {
            return 'mixed';
        }

        if (DB::table('planning_units')->where('code', 'ward_manager')->exists()) {
            return self::MEDICAL;
        }

        if (DB::table('planning_units')->where('code', 'like', 'vehicle_%')->exists()) {
            return self::VEHICLES;
        }

        $hasPlanningData = DB::table('planning_periods')->exists()
            || DB::table('resources')->exists()
            || DB::table('planning_units')->exists()
            || DB::table('demand_slots')->exists();

        return $hasPlanningData ? 'unknown' : null;
    }

    private function clearPlanningData(): void
    {
        foreach ([
            'planning_run_violations',
            'planning_run_score_components',
            'assignments',
            'planning_runs',
            'demand_slot_required_skill',
            'demand_slots',
            'resource_substitution_policies',
            'planning_unit_resource_rules',
            'calendar_holidays',
            'absences',
            'availability_rules',
            'resource_planning_limits',
            'planning_periods',
            'planning_unit_required_skill',
            'resource_skill',
            'resource_group_skill',
            'resources',
            'resource_groups',
            'planning_units',
            'shift_templates',
            'absence_types',
            'skills',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
