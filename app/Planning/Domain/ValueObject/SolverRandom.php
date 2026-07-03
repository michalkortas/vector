<?php

namespace App\Planning\Domain\ValueObject;

final class SolverRandom
{
    public function __construct(private int $state)
    {
        $this->state = max(1, $state);
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $this->state = (int) (($this->state * 1103515245 + 12345) & 0x7fffffff);

        return $min + ($this->state % ($max - $min + 1));
    }

    public function chance(float $probability): bool
    {
        return ($this->int(0, 1000000) / 1000000) < $probability;
    }

    public function pick(array $items, mixed $default = null): mixed
    {
        if ($items === []) {
            return $default;
        }

        return $items[$this->int(0, count($items) - 1)];
    }
}
