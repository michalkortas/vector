<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('planning_unit_resource_rules', 'shift_template_id')) {
            return;
        }

        Schema::table('planning_unit_resource_rules', function (Blueprint $table): void {
            $table->foreignId('shift_template_id')->nullable()->after('planning_unit_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('planning_unit_resource_rules', 'shift_template_id')) {
            return;
        }

        Schema::table('planning_unit_resource_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_template_id');
        });
    }
};
