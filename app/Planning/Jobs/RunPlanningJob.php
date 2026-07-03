<?php

namespace App\Planning\Jobs;

use App\Planning\Domain\DTO\SolverConfig;
use App\Planning\Engine\Contracts\SolverInterface;
use App\Planning\Infrastructure\EloquentPlanningProblemFactory;
use App\Planning\Infrastructure\EloquentPlanningResultPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunPlanningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $planningRunId)
    {
    }

    public function handle(EloquentPlanningProblemFactory $factory, EloquentPlanningResultPersister $persister, SolverInterface $solver): void
    {
        $run = (array) DB::table('planning_runs')->find($this->planningRunId);
        $started = microtime(true);
        $solverConfig = config('planning.solver');
        DB::table('planning_runs')->where('id', $this->planningRunId)->update([
            'status' => 'running',
            'started_at' => now(),
            'metadata' => json_encode([
                'phase' => 'preparing_problem',
                'evaluated_candidates' => 0,
                'completed_generations' => 0,
                'configured_generations' => (int) $solverConfig['generations'],
                'configured_population_size' => (int) $solverConfig['population_size'],
                'estimated_candidates' => $this->estimatedCandidates($solverConfig),
                'progress_percent' => 0,
            ]),
            'updated_at' => now(),
        ]);

        try {
            $problem = $factory->make((int) $run['planning_period_id']);
            $lastProgressWrite = 0.0;
            $lastProgressPercent = -1;
            $result = $solver->solve($problem, SolverConfig::fromArray($solverConfig, (int) $run['random_seed'], function (array $progress) use (&$lastProgressWrite, &$lastProgressPercent): void {
                $estimatedCandidates = max(1, (int) ($progress['estimated_candidates'] ?? 1));
                $evaluatedCandidates = (int) ($progress['evaluated_candidates'] ?? 0);
                $progressPercent = min(99, (int) floor(($evaluatedCandidates / $estimatedCandidates) * 100));
                $now = microtime(true);
                if (($now - $lastProgressWrite) < 0.5 && $progressPercent === $lastProgressPercent) {
                    return;
                }

                $lastProgressWrite = $now;
                $lastProgressPercent = $progressPercent;
                DB::table('planning_runs')->where('id', $this->planningRunId)->update([
                    'metadata' => json_encode([
                        ...$progress,
                        'progress_percent' => $progressPercent,
                    ]),
                    'updated_at' => now(),
                ]);
            }));
            $persister->persist($this->planningRunId, (int) $run['planning_period_id'], $result);
            DB::table('planning_runs')->where('id', $this->planningRunId)->update([
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'updated_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            DB::table('planning_runs')->where('id', $this->planningRunId)->update([
                'status' => 'failed',
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'updated_at' => now(),
            ]);
            throw $throwable;
        }
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
