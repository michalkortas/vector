<?php

namespace App\Planning\Domain\DTO;

final class PlanningResult
{
    public function __construct(
        public readonly ScheduleChromosome $chromosome,
        public readonly FitnessScore $score,
        public readonly array $metadata = [],
    ) {
    }
}
