<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\SolverConfig;

interface InitialPopulationFactoryInterface
{
    public function create(PlanningProblem $problem, CandidatePool $candidatePool, SolverConfig $config): array;
}
