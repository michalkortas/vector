<?php

namespace App\Http\Controllers;

use App\Planning\Jobs\RunPlanningJob;
use App\Planning\Infrastructure\PlanningRuleSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class PlanningRunController extends Controller
{
    public function store(int $period): RedirectResponse
    {
        $seed = (int) request('random_seed', 202607);
        $rules = PlanningRuleSettings::applyToConfig();
        $solverConfig = config('planning.solver');
        $runId = DB::table('planning_runs')->insertGetId([
            'planning_period_id' => $period,
            'status' => 'queued',
            'solver_name' => 'genetic',
            'random_seed' => $seed,
            'config' => json_encode(['solver' => $solverConfig, 'rules' => $rules]),
            'metadata' => json_encode([
                'phase' => 'queued',
                'evaluated_candidates' => 0,
                'completed_generations' => 0,
                'configured_generations' => (int) $solverConfig['generations'],
                'configured_population_size' => (int) $solverConfig['population_size'],
                'estimated_candidates' => $this->estimatedCandidates($solverConfig),
                'progress_percent' => 0,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RunPlanningJob::dispatch($runId);

        return back()->with('message', 'Uruchomiono generowanie grafiku.');
    }

    private function estimatedCandidates(array $solverConfig): int
    {
        $populationSize = (int) $solverConfig['population_size'];
        $eliteCount = (int) $solverConfig['elite_count'];
        $generations = (int) $solverConfig['generations'];
        $repairPasses = (int) $solverConfig['repair_passes'];

        return $populationSize + ($populationSize - $eliteCount) * $generations + max(1, $repairPasses);
    }
}
