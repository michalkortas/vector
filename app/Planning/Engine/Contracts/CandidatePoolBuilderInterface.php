<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;

interface CandidatePoolBuilderInterface
{
    public function build(PlanningProblem $problem): CandidatePool;
}
