<?php

namespace App\Planning\Domain\DTO;

final class SolverConfig
{
    public function __construct(
        public readonly int $populationSize,
        public readonly int $generations,
        public readonly int $eliteCount,
        public readonly float $crossoverRate,
        public readonly float $mutationRate,
        public readonly int $repairPasses,
        public readonly int $timeLimitSeconds,
        public readonly int $stagnationGenerations,
        public readonly int $randomSeed,
        public readonly mixed $progressReporter = null,
    ) {
    }

    public static function fromArray(array $config, int $seed, ?callable $progressReporter = null): self
    {
        return new self(
            (int) $config['population_size'],
            (int) $config['generations'],
            (int) $config['elite_count'],
            (float) $config['crossover_rate'],
            (float) $config['mutation_rate'],
            (int) $config['repair_passes'],
            (int) $config['time_limit_seconds'],
            (int) $config['stagnation_generations'],
            $seed,
            $progressReporter,
        );
    }
}
