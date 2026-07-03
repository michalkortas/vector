<?php

namespace App\Planning\Domain\DTO;

final class ScheduleChromosome
{
    public function __construct(public readonly array $genes)
    {
    }

    public function withGene(string $key, ?int $resourceId): self
    {
        $genes = $this->genes;
        $genes[$key] = $resourceId;

        return new self($genes);
    }
}
