<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resource_substitution_policies')) {
            return;
        }

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
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_substitution_policies');
    }
};
