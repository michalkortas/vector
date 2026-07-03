<?php

namespace App\Planning\Domain\Enum;

enum ConstraintSeverity: string
{
    case Hard = 'hard';
    case Soft = 'soft';
    case Info = 'info';
}
