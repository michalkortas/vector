<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;

interface MutationOperatorInterface
{
    public function mutate(ScheduleChromosome $chromosome, PlanningProblem $problem, CandidatePool $candidatePool, SolverRandom $random): ScheduleChromosome;
}
