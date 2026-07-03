<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\PlanningResult;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\SolverConfig;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\CandidatePoolBuilderInterface;
use App\Planning\Engine\Contracts\CrossoverOperatorInterface;
use App\Planning\Engine\Contracts\InitialPopulationFactoryInterface;
use App\Planning\Engine\Contracts\MutationOperatorInterface;
use App\Planning\Engine\Contracts\ObjectiveFunctionInterface;
use App\Planning\Engine\Contracts\ScheduleRepairerInterface;
use App\Planning\Engine\Contracts\SelectionStrategyInterface;
use App\Planning\Engine\Contracts\SolverInterface;

final class GeneticSolver implements SolverInterface
{
    public function __construct(
        private readonly CandidatePoolBuilderInterface $candidatePoolBuilder,
        private readonly InitialPopulationFactoryInterface $initialPopulationFactory,
        private readonly SelectionStrategyInterface $selection,
        private readonly array $crossovers,
        private readonly MutationOperatorInterface $mutation,
        private readonly ScheduleRepairerInterface $repairer,
        private readonly ObjectiveFunctionInterface $objective,
    ) {
    }

    public function solve(PlanningProblem $problem, SolverConfig $config): PlanningResult
    {
        $started = microtime(true);
        $random = new SolverRandom($config->randomSeed);
        $pool = $this->candidatePoolBuilder->build($problem);
        $initialChromosomes = array_map(function (ScheduleChromosome $chromosome) use ($problem, $pool, $config): ScheduleChromosome {
            for ($pass = 0; $pass < $config->repairPasses; $pass++) {
                $chromosome = $this->repairer->repair($problem, $chromosome, $pool);
            }

            return $chromosome;
        }, $this->initialPopulationFactory->create($problem, $pool, $config));
        $population = $this->scorePopulation($problem, $initialChromosomes);
        $evaluatedCandidates = count($population);
        $best = $population[0];
        $history = [$best['score']->total];
        $stagnation = 0;
        $completedGenerations = 0;
        $stopReason = 'generations_completed';
        $this->reportProgress($config, [
            'phase' => 'initial_population_scored',
            'evaluated_candidates' => $evaluatedCandidates,
            'completed_generations' => $completedGenerations,
            'configured_generations' => $config->generations,
            'configured_population_size' => $config->populationSize,
            'best_score' => $best['score']->total,
        ]);

        for ($generation = 1; $generation <= $config->generations; $generation++) {
            if ((microtime(true) - $started) >= $config->timeLimitSeconds) {
                $stopReason = 'time_limit';
                break;
            }
            if ($stagnation >= $config->stagnationGenerations) {
                $stopReason = 'stagnation';
                break;
            }

            $next = array_slice($population, 0, $config->eliteCount);
            while (count($next) < $config->populationSize) {
                $parentA = $this->selection->select($population, $random);
                $parentB = $this->selection->select($population, $random);
                $child = $random->chance($config->crossoverRate)
                    ? $this->crossover($parentA, $parentB, $random)
                    : $parentA;

                if ($random->chance($config->mutationRate)) {
                    $child = $this->mutation->mutate($child, $problem, $pool, $random);
                }
                for ($pass = 0; $pass < $config->repairPasses; $pass++) {
                    $child = $this->repairer->repair($problem, $child, $pool);
                }
                $next[] = ['chromosome' => $child, 'score' => $this->objective->evaluate($problem, $child)];
                $evaluatedCandidates++;
                $this->reportProgress($config, [
                    'phase' => 'evolving',
                    'evaluated_candidates' => $evaluatedCandidates,
                    'completed_generations' => $completedGenerations,
                    'configured_generations' => $config->generations,
                    'configured_population_size' => $config->populationSize,
                    'current_generation' => $generation,
                    'current_generation_candidates' => count($next),
                    'estimated_candidates' => $this->estimatedCandidates($config),
                    'best_score' => $best['score']->total,
                ]);
            }

            $this->sortPopulation($next);
            $population = $next;
            $completedGenerations = $generation;
            if ($this->isBetter($population[0], $best)) {
                $best = $population[0];
                $stagnation = 0;
            } else {
                $stagnation++;
            }
            $history[] = $best['score']->total;
            $this->reportProgress($config, [
                'phase' => 'evolving',
                'evaluated_candidates' => $evaluatedCandidates,
                'completed_generations' => $completedGenerations,
                'configured_generations' => $config->generations,
                'configured_population_size' => $config->populationSize,
                'best_score' => $best['score']->total,
            ]);
        }

        $final = $best;
        for ($pass = 0; $pass < max(1, $config->repairPasses); $pass++) {
            $chromosome = $this->repairer->repair($problem, $final['chromosome'], $pool);
            $candidate = ['chromosome' => $chromosome, 'score' => $this->objective->evaluate($problem, $chromosome)];
            $evaluatedCandidates++;
            if ($this->isBetter($candidate, $final)) {
                $final = $candidate;
            }
        }
        $best = $final;

        return new PlanningResult($best['chromosome'], $best['score'], [
            'best_score_history' => $history,
            'evaluated_candidates' => $evaluatedCandidates,
            'completed_generations' => $completedGenerations,
            'configured_population_size' => $config->populationSize,
            'configured_generations' => $config->generations,
            'estimated_candidates' => $this->estimatedCandidates($config),
            'progress_percent' => 100,
            'stop_reason' => $stopReason,
            'time_limit_seconds' => $config->timeLimitSeconds,
            'stagnation_generations' => $config->stagnationGenerations,
            'random_seed' => $config->randomSeed,
            'candidate_pool_sizes' => array_map('count', $pool->candidatesByGene),
        ]);
    }

    private function scorePopulation(PlanningProblem $problem, array $chromosomes): array
    {
        $population = array_map(fn (ScheduleChromosome $chromosome): array => [
            'chromosome' => $chromosome,
            'score' => $this->objective->evaluate($problem, $chromosome),
        ], $chromosomes);
        $this->sortPopulation($population);

        return $population;
    }

    private function sortPopulation(array &$population): void
    {
        usort($population, fn (array $a, array $b): int => [
            $a['score']->hardViolationsCount,
            $a['score']->unassignedSlotsCount,
            $a['score']->total,
        ] <=> [
            $b['score']->hardViolationsCount,
            $b['score']->unassignedSlotsCount,
            $b['score']->total,
        ]);
    }

    private function isBetter(array $candidate, array $current): bool
    {
        return [
            $candidate['score']->hardViolationsCount,
            $candidate['score']->unassignedSlotsCount,
            $candidate['score']->total,
        ] < [
            $current['score']->hardViolationsCount,
            $current['score']->unassignedSlotsCount,
            $current['score']->total,
        ];
    }

    private function reportProgress(SolverConfig $config, array $progress): void
    {
        if (is_callable($config->progressReporter)) {
            ($config->progressReporter)($progress);
        }
    }

    private function estimatedCandidates(SolverConfig $config): int
    {
        return $config->populationSize + ($config->populationSize - $config->eliteCount) * $config->generations + max(1, $config->repairPasses);
    }

    private function crossover(ScheduleChromosome $a, ScheduleChromosome $b, SolverRandom $random): ScheduleChromosome
    {
        /** @var CrossoverOperatorInterface $operator */
        $operator = $random->pick($this->crossovers);

        return $operator->crossover($a, $b, $random);
    }
}
