<?php

namespace App\Planning\Domain\DTO;

final class ScoreComponent
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly int $score,
        public readonly ?int $weight = null,
        public readonly bool $hard = false,
        public readonly array $metadata = [],
    ) {
    }
}
