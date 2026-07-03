<?php

namespace App\Planning\Engine\Contracts;

use App\Planning\Domain\DTO\CandidatePool;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;

interface ScheduleRepairerInterface
{
    public function repair(PlanningProblem $problem, ScheduleChromosome $chromosome, CandidatePool $candidatePool): ScheduleChromosome;
}
