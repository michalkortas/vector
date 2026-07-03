<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('employee_number')->nullable()->index();
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_skill', function (Blueprint $table): void {
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('imported');
            $table->primary(['resource_id', 'skill_id']);
        });

        Schema::create('resource_group_skill', function (Blueprint $table): void {
            $table->foreignId('resource_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['resource_group_id', 'skill_id']);
        });

        Schema::create('planning_units', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->foreignId('parent_id')->nullable()->constrained('planning_units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('planning_unit_required_skill', function (Blueprint $table): void {
            $table->foreignId('planning_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_mode')->default('required');
            $table->primary(['planning_unit_id', 'skill_id']);
        });

        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('duration_minutes');
            $table->boolean('crosses_midnight')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('planning_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('monthly_norm_minutes')->nullable();
            $table->unsignedInteger('quarterly_norm_minutes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('demand_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('required_resources_count')->default(1);
            $table->unsignedInteger('priority')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('demand_slot_required_skill', function (Blueprint $table): void {
            $table->foreignId('demand_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_mode')->default('required');
            $table->primary(['demand_slot_id', 'skill_id']);
        });

        Schema::create('absence_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('blocks_planning')->default(true);
            $table->boolean('counts_as_work_time')->default(false);
            $table->unsignedInteger('nominal_minutes_per_day')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('absence_type_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('blocks_planning')->default(true);
            $table->boolean('counts_as_work_time')->default(false);
            $table->unsignedInteger('nominal_minutes')->nullable();
            $table->string('source')->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('rule_type');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_planning_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_period_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('max_minutes_per_day')->nullable();
            $table->unsignedInteger('max_minutes_per_month')->nullable();
            $table->unsignedInteger('max_minutes_per_quarter')->nullable();
            $table->unsignedInteger('target_minutes_per_month')->nullable();
            $table->unsignedInteger('target_minutes_per_quarter')->nullable();
            $table->unsignedInteger('min_rest_minutes')->nullable();
            $table->unsignedInteger('max_night_shifts_per_month')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('planning_unit_resource_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('usage_mode')->default('primary');
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedInteger('penalty')->default(0);
            $table->unsignedInteger('max_assignments_per_period')->nullable();
            $table->unsignedInteger('max_minutes_per_period')->nullable();
            $table->boolean('requires_manual_approval')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_substitution_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_planning_unit_id')->constrained('planning_units')->cascadeOnDelete();
            $table->foreignId('primary_shift_template_id')->nullable()->constrained('shift_templates')->nullOnDelete();
            $table->string('when_used_as_usage_mode')->default('fallback');
            $table->string('effect')->default('allow_primary_slot_unassigned');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('planning_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_period_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->string('solver_name');
            $table->unsignedInteger('random_seed')->nullable();
            $table->json('config')->nullable();
            $table->integer('score_total')->nullable();
            $table->unsignedInteger('hard_violations_count')->default(0);
            $table->unsignedInteger('soft_violations_count')->default(0);
            $table->unsignedInteger('unassigned_slots_count')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demand_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot_position')->default(1);
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('planning_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source')->default('generated');
            $table->boolean('is_locked')->default(false);
            $table->integer('score_delta')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['demand_slot_id', 'slot_position', 'planning_run_id'], 'assignments_slot_run_unique');
        });

        Schema::create('planning_run_score_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_run_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->integer('score');
            $table->integer('weight')->nullable();
            $table->boolean('hard')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('planning_run_violations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_run_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('severity');
            $table->text('message');
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demand_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'planning_run_violations',
            'planning_run_score_components',
            'assignments',
            'planning_runs',
            'resource_substitution_policies',
            'planning_unit_resource_rules',
            'resource_planning_limits',
            'availability_rules',
            'absences',
            'absence_types',
            'demand_slot_required_skill',
            'demand_slots',
            'planning_periods',
            'shift_templates',
            'planning_unit_required_skill',
            'planning_units',
            'resource_group_skill',
            'resource_skill',
            'skills',
            'resources',
            'resource_groups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
