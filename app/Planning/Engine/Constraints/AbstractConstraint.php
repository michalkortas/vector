<?php

namespace App\Planning\Engine\Constraints;

use App\Planning\Domain\DTO\Violation;
use App\Planning\Domain\Enum\ConstraintSeverity;

abstract class AbstractConstraint
{
    public function severity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    protected function violation(string $message, ?int $resourceId = null, ?int $slotId = null, array $metadata = []): Violation
    {
        return new Violation($this->code(), $this->severity(), $message, $resourceId, $slotId, $metadata);
    }
}
