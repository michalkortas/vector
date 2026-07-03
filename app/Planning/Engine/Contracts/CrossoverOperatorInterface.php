<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;

interface CrossoverOperatorInterface
{
    public function crossover(ScheduleChromosome $a, ScheduleChromosome $b, SolverRandom $random): ScheduleChromosome;
}
