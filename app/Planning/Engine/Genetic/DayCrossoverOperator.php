<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\CrossoverOperatorInterface;

final class DayCrossoverOperator implements CrossoverOperatorInterface
{
    public function crossover(ScheduleChromosome $a, ScheduleChromosome $b, SolverRandom $random): ScheduleChromosome
    {
        $genes = [];
        foreach ($a->genes as $key => $value) {
            [$slotId] = array_map('intval', explode(':', $key));
            $genes[$key] = ($slotId % 2 === 0) ? $value : ($b->genes[$key] ?? null);
        }

        return new ScheduleChromosome($genes);
    }
}
