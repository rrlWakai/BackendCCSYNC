<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_time_grids', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->string('timezone', 64);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'version'], 'scheduling_time_grids_term_version_unique');
            $table->index(['academic_term_id', 'status'], 'scheduling_time_grids_term_status_index');
        });

        Schema::create('scheduling_time_grid_days', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('time_grid_id')->constrained('scheduling_time_grids')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('operating_start');
            $table->time('operating_end');
            $table->timestamps();

            $table->unique(['time_grid_id', 'day_of_week'], 'scheduling_time_grid_days_grid_day_unique');
        });

        Schema::create('scheduling_time_grid_slots', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('time_grid_day_id')->constrained('scheduling_time_grid_days')->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('label', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['time_grid_day_id', 'start_time'], 'scheduling_time_grid_slots_day_start_unique');
        });

        DB::statement('ALTER TABLE scheduling_time_grid_days ADD CONSTRAINT scheduling_time_grid_days_day_check CHECK (day_of_week BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE scheduling_time_grid_days ADD CONSTRAINT scheduling_time_grid_days_hours_check CHECK (operating_start < operating_end)');
        DB::statement('ALTER TABLE scheduling_time_grid_slots ADD CONSTRAINT scheduling_time_grid_slots_hours_check CHECK (start_time < end_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_time_grid_slots');
        Schema::dropIfExists('scheduling_time_grid_days');
        Schema::dropIfExists('scheduling_time_grids');
    }
};
