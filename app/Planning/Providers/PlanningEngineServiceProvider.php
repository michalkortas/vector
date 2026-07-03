<?php

namespace App\Planning\Providers;

use App\Planning\Engine\CandidatePool\DefaultCandidatePoolBuilder;
use App\Planning\Engine\Constraints\AbsenceConflictConstraint;
use App\Planning\Engine\Constraints\AvailabilityConflictConstraint;
use App\Planning\Engine\Constraints\DailyLimitConstraint;
use App\Planning\Engine\Constraints\ExcludedResourceConstraint;
use App\Planning\Engine\Constraints\HolidayConflictConstraint;
use App\Planning\Engine\Constraints\LockedAssignmentConstraint;
use App\Planning\Engine\Constraints\MinimumRestConstraint;
use App\Planning\Engine\Constraints\MissingSkillConstraint;
use App\Planning\Engine\Constraints\MonthlyLimitConstraint;
use App\Planning\Engine\Constraints\NominalLimitConstraint;
use App\Planning\Engine\Constraints\OverlappingAssignmentsConstraint;
use App\Planning\Engine\Constraints\QuarterlyLimitConstraint;
use App\Planning\Engine\Constraints\SeniorCoverageConstraint;
use App\Planning\Engine\Contracts\CandidatePoolBuilderInterface;
use App\Planning\Engine\Contracts\InitialPopulationFactoryInterface;
use App\Planning\Engine\Contracts\MutationOperatorInterface;
use App\Planning\Engine\Contracts\ObjectiveFunctionInterface;
use App\Planning\Engine\Contracts\ScheduleRepairerInterface;
use App\Planning\Engine\Contracts\SelectionStrategyInterface;
use App\Planning\Engine\Contracts\SolverInterface;
use App\Planning\Engine\Genetic\DayCrossoverOperator;
use App\Planning\Engine\Genetic\DomainMutationOperator;
use App\Planning\Engine\Genetic\GeneticSolver;
use App\Planning\Engine\Genetic\InitialPopulationFactory;
use App\Planning\Engine\Genetic\PlanningUnitCrossoverOperator;
use App\Planning\Engine\Genetic\TournamentSelectionStrategy;
use App\Planning\Engine\Repair\DefaultScheduleRepairer;
use App\Planning\Engine\Scoring\ConsecutiveNightShiftScoreRule;
use App\Planning\Engine\Scoring\ContractUsageScoreRule;
use App\Planning\Engine\Scoring\EvenHoursDistributionScoreRule;
use App\Planning\Engine\Scoring\FallbackUsageScoreRule;
use App\Planning\Engine\Scoring\NightShiftDistributionScoreRule;
use App\Planning\Engine\Scoring\NightRecoveryAfterNightScoreRule;
use App\Planning\Engine\Scoring\PlanningUnitResourcePolicyScoreRule;
use App\Planning\Engine\Scoring\SecondaryUsageScoreRule;
use App\Planning\Engine\Scoring\SameResourceStreakScoreRule;
use App\Planning\Engine\Scoring\UnassignedSlotScoreRule;
use App\Planning\Engine\Scoring\WeekendDistributionScoreRule;
use App\Planning\Engine\Scoring\WeightedObjectiveFunction;
use App\Planning\Infrastructure\PlanningRuleSettings;
use Illuminate\Support\ServiceProvider;

final class PlanningEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CandidatePoolBuilderInterface::class, DefaultCandidatePoolBuilder::class);
        $this->app->bind(InitialPopulationFactoryInterface::class, InitialPopulationFactory::class);
        $this->app->bind(SelectionStrategyInterface::class, TournamentSelectionStrategy::class);
        $this->app->bind(MutationOperatorInterface::class, DomainMutationOperator::class);
        $this->app->bind(ScheduleRepairerInterface::class, DefaultScheduleRepairer::class);

        $this->app->bind(ObjectiveFunctionInterface::class, fn (): WeightedObjectiveFunction => new WeightedObjectiveFunction(
            [new MissingSkillConstraint(), new SeniorCoverageConstraint(), new AbsenceConflictConstraint(), new AvailabilityConflictConstraint(), new HolidayConflictConstraint(), new OverlappingAssignmentsConstraint(), new MinimumRestConstraint(), new DailyLimitConstraint(), new NominalLimitConstraint(), new MonthlyLimitConstraint(), new QuarterlyLimitConstraint(), new ExcludedResourceConstraint(), new LockedAssignmentConstraint()],
            [new UnassignedSlotScoreRule(), new PlanningUnitResourcePolicyScoreRule(), new EvenHoursDistributionScoreRule(), new ContractUsageScoreRule(), new NightRecoveryAfterNightScoreRule(), new ConsecutiveNightShiftScoreRule(), new SameResourceStreakScoreRule(), new NightShiftDistributionScoreRule(), new WeekendDistributionScoreRule(), new SecondaryUsageScoreRule(), new FallbackUsageScoreRule()],
            PlanningRuleSettings::applyToConfig(),
        ));

        $this->app->bind(SolverInterface::class, fn ($app): GeneticSolver => new GeneticSolver(
            $app->make(CandidatePoolBuilderInterface::class),
            $app->make(InitialPopulationFactoryInterface::class),
            $app->make(SelectionStrategyInterface::class),
            [new DayCrossoverOperator(), new PlanningUnitCrossoverOperator()],
            $app->make(MutationOperatorInterface::class),
            $app->make(ScheduleRepairerInterface::class),
            $app->make(ObjectiveFunctionInterface::class),
        ));
    }
}
