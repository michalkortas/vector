<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_holidays')) {
            return;
        }

        Schema::create('calendar_holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('holiday_date');
            $table->string('name');
            $table->string('scope')->default('global');
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('resource_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('blocks_planning')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_holidays');
    }
};
