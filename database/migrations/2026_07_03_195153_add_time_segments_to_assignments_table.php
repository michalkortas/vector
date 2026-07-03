<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }

        Schema::table('assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('assignments', 'segment_position')) {
                $table->unsignedInteger('segment_position')->default(1)->after('slot_position');
            }
            if (! Schema::hasColumn('assignments', 'starts_at')) {
                $table->dateTime('starts_at')->nullable()->after('planning_run_id');
            }
            if (! Schema::hasColumn('assignments', 'ends_at')) {
                $table->dateTime('ends_at')->nullable()->after('starts_at');
            }
            if (! Schema::hasColumn('assignments', 'duration_minutes')) {
                $table->unsignedInteger('duration_minutes')->nullable()->after('ends_at');
            }
        });

        if (! $this->indexExists('assignments', 'assignments_demand_slot_id_segment_fk')) {
            DB::statement('ALTER TABLE assignments ADD INDEX assignments_demand_slot_id_segment_fk (demand_slot_id)');
        }
        if ($this->indexExists('assignments', 'assignments_slot_run_unique')) {
            DB::statement('ALTER TABLE assignments DROP INDEX assignments_slot_run_unique');
        }
        if (! $this->indexExists('assignments', 'assignments_slot_segment_run_unique')) {
            DB::statement('ALTER TABLE assignments ADD UNIQUE assignments_slot_segment_run_unique (demand_slot_id, slot_position, planning_run_id, segment_position)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }

        if ($this->indexExists('assignments', 'assignments_slot_segment_run_unique')) {
            DB::statement('ALTER TABLE assignments DROP INDEX assignments_slot_segment_run_unique');
        }
        if (! $this->indexExists('assignments', 'assignments_slot_run_unique')) {
            DB::statement('ALTER TABLE assignments ADD UNIQUE assignments_slot_run_unique (demand_slot_id, slot_position, planning_run_id)');
        }
        if ($this->indexExists('assignments', 'assignments_demand_slot_id_segment_fk')) {
            DB::statement('ALTER TABLE assignments DROP INDEX assignments_demand_slot_id_segment_fk');
        }

        Schema::table('assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('assignments', 'duration_minutes')) {
                $table->dropColumn('duration_minutes');
            }
            if (Schema::hasColumn('assignments', 'ends_at')) {
                $table->dropColumn('ends_at');
            }
            if (Schema::hasColumn('assignments', 'starts_at')) {
                $table->dropColumn('starts_at');
            }
            if (Schema::hasColumn('assignments', 'segment_position')) {
                $table->dropColumn('segment_position');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
