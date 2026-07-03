<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\MutationOperatorInterface;

final class DomainMutationOperator implements MutationOperatorInterface
{
    public function mutate(ScheduleChromosome $chromosome, PlanningProblem $problem, CandidatePool $candidatePool, SolverRandom $random): ScheduleChromosome
    {
        $genes = $chromosome->genes;
        $keys = array_keys($genes);
        if ($keys === []) {
            return $chromosome;
        }

        $key = $random->pick($keys);
        if (array_key_exists($key, $problem->lockedAssignments)) {
            return $chromosome;
        }

        if ($random->chance(0.65)) {
            $candidates = $candidatePool->candidates($key);
            $normalCandidates = array_values(array_filter($candidates, fn (array $candidate): bool => ! in_array($candidate['usage_mode'], ['fallback', 'emergency_only'], true)));
            $preferredCandidates = $normalCandidates !== [] ? $normalCandidates : $candidates;
            $primary = array_values(array_filter($preferredCandidates, fn (array $candidate): bool => $candidate['usage_mode'] === 'primary'));
            $candidate = $random->pick($primary !== [] ? $primary : $preferredCandidates);
            $genes[$key] = $candidate['resource_id'] ?? null;
        } else {
            $other = $random->pick($keys);
            if (! array_key_exists($other, $problem->lockedAssignments)) {
                [$genes[$key], $genes[$other]] = [$genes[$other], $genes[$key]];
            }
        }

        return new ScheduleChromosome($genes);
    }
}
