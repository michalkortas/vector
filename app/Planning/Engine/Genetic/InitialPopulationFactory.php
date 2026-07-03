<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\SolverConfig;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\InitialPopulationFactoryInterface;

final class InitialPopulationFactory implements InitialPopulationFactoryInterface
{
    public function create(PlanningProblem $problem, CandidatePool $candidatePool, SolverConfig $config): array
    {
        $random = new SolverRandom($config->randomSeed);
        $population = [];

        for ($i = 0; $i < $config->populationSize; $i++) {
            $genes = [];
            $load = [];
            foreach ($problem->slotPositions() as $position) {
                $key = $problem->geneKey($position['slot_id'], $position['position']);
                if (array_key_exists($key, $problem->lockedAssignments)) {
                    $genes[$key] = $problem->lockedAssignments[$key];
                    continue;
                }

                $candidates = $candidatePool->candidates($key);
                if ($candidates === []) {
                    $genes[$key] = null;
                    continue;
                }
                $normalCandidates = array_values(array_filter($candidates, fn (array $candidate): bool => ! in_array($candidate['usage_mode'], ['fallback', 'emergency_only'], true)));
                $preferredCandidates = $normalCandidates !== [] ? $normalCandidates : $candidates;

                if ($i % 3 === 0) {
                    usort($preferredCandidates, fn (array $a, array $b): int => [($load[$a['resource_id']] ?? 0), $a['penalty']] <=> [($load[$b['resource_id']] ?? 0), $b['penalty']]);
                    $candidate = $preferredCandidates[0];
                } elseif ($i % 3 === 1) {
                    $primary = array_values(array_filter($preferredCandidates, fn (array $candidate): bool => $candidate['usage_mode'] === 'primary'));
                    $candidate = $random->pick($primary !== [] ? $primary : $preferredCandidates);
                } else {
                    $candidate = $random->pick($preferredCandidates);
                }

                $genes[$key] = $candidate['resource_id'];
                $slot = $problem->slot($position['slot_id']);
                $load[$candidate['resource_id']] = ($load[$candidate['resource_id']] ?? 0) + (int) ($slot['duration_minutes'] ?? 0);
            }
            $population[] = new ScheduleChromosome($genes);
        }

        return $population;
    }
}
