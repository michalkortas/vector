<?php

namespace App\Planning\Domain\DTO;

final class CandidatePool
{
    public function __construct(public readonly array $candidatesByGene)
    {
    }

    public function candidates(string $geneKey): array
    {
        return $this->candidatesByGene[$geneKey] ?? [];
    }
}
