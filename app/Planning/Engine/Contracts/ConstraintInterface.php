<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\Enum\ConstraintSeverity;

interface ConstraintInterface
{
    public function code(): string;
    public function severity(): ConstraintSeverity;
    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): array;
}
