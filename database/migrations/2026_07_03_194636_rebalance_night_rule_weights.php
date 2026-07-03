<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planning_rule_settings')) {
            return;
        }

        DB::table('planning_rule_settings')
            ->where('code', 'consecutive_nights')
            ->where('weight', 20000)
            ->update(['weight' => 250000, 'updated_at' => now()]);

        DB::table('planning_rule_settings')
            ->where('code', 'even_nights')
            ->where('weight', 5000)
            ->update(['weight' => 500, 'updated_at' => now()]);

        DB::table('planning_rule_settings')
            ->where('code', 'even_weekends')
            ->where('weight', 5000)
            ->update(['weight' => 500, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('planning_rule_settings')) {
            return;
        }

        DB::table('planning_rule_settings')
            ->where('code', 'consecutive_nights')
            ->where('weight', 250000)
            ->update(['weight' => 20000, 'updated_at' => now()]);

        DB::table('planning_rule_settings')
            ->where('code', 'even_nights')
            ->where('weight', 500)
            ->update(['weight' => 5000, 'updated_at' => now()]);

        DB::table('planning_rule_settings')
            ->where('code', 'even_weekends')
            ->where('weight', 500)
            ->update(['weight' => 5000, 'updated_at' => now()]);
    }
};
