<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planning_rule_settings') || Schema::hasColumn('planning_rule_settings', 'can_toggle')) {
            return;
        }

        Schema::table('planning_rule_settings', function (Blueprint $table): void {
            $table->boolean('can_toggle')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('planning_rule_settings') || ! Schema::hasColumn('planning_rule_settings', 'can_toggle')) {
            return;
        }

        Schema::table('planning_rule_settings', function (Blueprint $table): void {
            $table->dropColumn('can_toggle');
        });
    }
};
