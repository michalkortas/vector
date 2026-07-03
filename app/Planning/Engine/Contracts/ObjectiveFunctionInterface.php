<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\FitnessScore;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;

interface ObjectiveFunctionInterface
{
    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): FitnessScore;
}
