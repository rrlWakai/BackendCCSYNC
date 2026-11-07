<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_availability', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('professor_id')->constrained('professors')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('availability_type', 16);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['professor_id', 'academic_term_id', 'day_of_week', 'start_time', 'end_time'], 'faculty_availability_window_unique');
            $table->index(['professor_id', 'academic_term_id', 'day_of_week'], 'faculty_availability_professor_term_day_index');
            $table->index(['academic_term_id', 'day_of_week', 'start_time', 'end_time'], 'faculty_availability_term_day_time_index');
        });

        DB::statement('ALTER TABLE faculty_availability ADD CONSTRAINT faculty_availability_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE faculty_availability ADD CONSTRAINT faculty_availability_time_check CHECK (start_time < end_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_availability');
    }
};
