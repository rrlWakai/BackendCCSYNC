<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('major_selection_periods', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('target_year_level_id')->constrained('year_levels')->restrictOnDelete();
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->boolean('allow_changes')->default(true);
            $table->string('status', 16)->default('planned');
            $table->timestamps();

            $table->unique(
                ['academic_year_id', 'program_id', 'target_year_level_id'],
                'major_selection_periods_scope_unique',
            );
            $table->index(['status', 'opens_at', 'closes_at'], 'major_selection_periods_window_index');
        });

        DB::statement('ALTER TABLE major_selection_periods ADD CONSTRAINT major_selection_periods_window_check CHECK (opens_at < closes_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('major_selection_periods');
    }
};
