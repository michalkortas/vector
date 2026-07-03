<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;

interface SelectionStrategyInterface
{
    public function select(array $population, SolverRandom $random): ScheduleChromosome;
}
