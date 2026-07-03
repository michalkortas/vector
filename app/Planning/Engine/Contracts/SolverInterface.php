<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\PlanningResult;
use App\Planning\Domain\DTO\SolverConfig;

interface SolverInterface
{
    public function solve(PlanningProblem $problem, SolverConfig $config): PlanningResult;
}
