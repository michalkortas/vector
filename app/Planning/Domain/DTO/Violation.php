<?php

namespace App\Planning\Domain\DTO;

use App\Planning\Domain\Enum\ConstraintSeverity;

final class Violation
{
    public function __construct(
        public readonly string $code,
        public readonly ConstraintSeverity $severity,
        public readonly string $message,
        public readonly ?int $resourceId = null,
        public readonly ?int $demandSlotId = null,
        public readonly array $metadata = [],
    ) {
    }
}
