<?php

namespace App\Planning\Domain\DTO;

final class FitnessScore
{
    public function __construct(
        public readonly int $total,
        public readonly array $components,
        public readonly array $violations,
        public readonly int $hardViolationsCount,
        public readonly int $softViolationsCount,
        public readonly int $unassignedSlotsCount,
    ) {
    }
}
