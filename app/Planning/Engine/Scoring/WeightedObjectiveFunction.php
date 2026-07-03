<?php

namespace App\Planning\Engine\Scoring;

use App\Planning\Domain\DTO\FitnessScore;
use App\Planning\Domain\DTO\PlanningProblem;
use App\Planning\Domain\DTO\ScheduleChromosome;
use App\Planning\Domain\DTO\ScoreComponent;
use App\Planning\Domain\DTO\Violation;
use App\Planning\Domain\Enum\ConstraintSeverity;
use App\Planning\Engine\Contracts\ConstraintInterface;
use App\Planning\Engine\Contracts\ObjectiveFunctionInterface;
use App\Planning\Engine\Contracts\ScoreRuleInterface;

final class WeightedObjectiveFunction implements ObjectiveFunctionInterface
{
    public function __construct(
        private readonly array $constraints,
        private readonly array $scoreRules,
        private readonly array $ruleSettings = [],
    ) {
    }

    public function evaluate(PlanningProblem $problem, ScheduleChromosome $chromosome): FitnessScore
    {
        $violations = [];
        $components = [];

        foreach ($this->constraints as $constraint) {
            /** @var ConstraintInterface $constraint */
            if (! $this->isActive($constraint->code())) {
                continue;
            }
            $constraintViolations = $constraint->evaluate($problem, $chromosome);
            $violations = [...$violations, ...$constraintViolations];
            if ($constraintViolations !== []) {
                $weight = (int) config('planning.weights.'.$constraint->code(), 100000);
                $components[] = new ScoreComponent($constraint->code(), $constraint->code(), count($constraintViolations) * $weight, $weight, $constraint->severity() === ConstraintSeverity::Hard);
            }
        }

        foreach ($this->scoreRules as $rule) {
            /** @var ScoreRuleInterface $rule */
            if (! $this->isActive($rule->code())) {
                continue;
            }
            $components[] = $rule->evaluate($problem, $chromosome);
        }

        $hard = count(array_filter($violations, fn (Violation $violation): bool => $violation->severity === ConstraintSeverity::Hard));
        $soft = count(array_filter($violations, fn (Violation $violation): bool => $violation->severity === ConstraintSeverity::Soft));
        $unassigned = count(array_filter($chromosome->genes, fn (?int $resourceId): bool => $resourceId === null));

        return new FitnessScore(
            array_sum(array_map(fn (ScoreComponent $component): int => $component->score, $components)),
            $components,
            $violations,
            $hard,
            $soft,
            $unassigned,
        );
    }

    private function isActive(string $code): bool
    {
        return (bool) ($this->ruleSettings[$code]['is_active'] ?? true);
    }
}
