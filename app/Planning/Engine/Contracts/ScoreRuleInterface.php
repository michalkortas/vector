<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;

interface ScoreRuleInterface
{
    public function code(): string;
    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): ScoreComponent;
}
