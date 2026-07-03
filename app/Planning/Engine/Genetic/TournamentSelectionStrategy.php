<?php

namespace App\Planning\Engine\Genetic;

use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\ValueObject\SolverRandom;
use App\Planning\Engine\Contracts\SelectionStrategyInterface;

final class TournamentSelectionStrategy implements SelectionStrategyInterface
{
    public function select(array $population, SolverRandom $random): ScheduleChromosome
    {
        $sample = [];
        for ($i = 0; $i < min(4, count($population)); $i++) {
            $sample[] = $random->pick($population);
        }
        usort($sample, fn (array $a, array $b): int => $a['score']->total <=> $b['score']->total);

        return $sample[0]['chromosome'];
    }
}
