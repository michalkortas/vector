<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\CrossoverOperatorInterface;

final class PlanningUnitCrossoverOperator implements CrossoverOperatorInterface
{
    public function crossover(ScheduleChromosome $a, ScheduleChromosome $b, SolverRandom $random): ScheduleChromosome
    {
        $genes = [];
        $takeA = true;
        $lastBucket = null;
        foreach ($a->genes as $key => $value) {
            [$slotId] = array_map('intval', explode(':', $key));
            $bucket = $slotId % 3;
            if ($bucket !== $lastBucket) {
                $takeA = ! $takeA;
                $lastBucket = $bucket;
            }
            $genes[$key] = $takeA ? $value : ($b->genes[$key] ?? null);
        }

        return new ScheduleChromosome($genes);
    }
}
