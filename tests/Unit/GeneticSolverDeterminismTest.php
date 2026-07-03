<?php

use App\Planning\Domain\DTO\SolverConfig;
use App\Planning\Engine\Contracts\SolverInterface;
use App\Planning\Infrastructure\EloquentPlanningProblemFactory;
use Illuminate\Support\Facades\DB;

it('is deterministic for the same seed', function (): void {
    config()->set('planning.solver.population_size', 10);
    config()->set('planning.solver.generations', 6);
    config()->set('planning.solver.time_limit_seconds', 5);
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $problem = app(EloquentPlanningProblemFactory::class)->make($periodId);
    $config = SolverConfig::fromArray(config('planning.solver'), 12345);

    $first = app(SolverInterface::class)->solve($problem, $config);
    $second = app(SolverInterface::class)->solve($problem, $config);

    expect($first->chromosome->genes)->toBe($second->chromosome->genes)
        ->and($first->score->total)->toBe($second->score->total);
});

it('reports solver progress while evolving generations', function (): void {
    config()->set('planning.solver.population_size', 8);
    config()->set('planning.solver.generations', 3);
    config()->set('planning.solver.time_limit_seconds', 5);
    $this->seed();

    $periodId = (int) DB::table('planning_periods')->where('starts_on', '2026-07-01')->value('id');
    $problem = app(EloquentPlanningProblemFactory::class)->make($periodId);
    $events = [];
    $config = SolverConfig::fromArray(config('planning.solver'), 12345, function (array $progress) use (&$events): void {
        $events[] = $progress;
    });

    $result = app(SolverInterface::class)->solve($problem, $config);

    expect($events)->not->toBeEmpty()
        ->and(max(array_column($events, 'evaluated_candidates')))->toBeGreaterThan(0)
        ->and(max(array_column($events, 'estimated_candidates')))->toBeGreaterThan(0)
        ->and(max(array_column($events, 'completed_generations')))->toBeGreaterThan(0)
        ->and($result->metadata['stop_reason'])->toBe('generations_completed')
        ->and($events[0]['configured_generations'])->toBe(3);
});
