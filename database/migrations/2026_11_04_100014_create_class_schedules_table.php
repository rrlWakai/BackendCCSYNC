<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('schedule_type', 20);
            $table->string('modality', 20);
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->unique(['subject_offering_id', 'day_of_week', 'start_time', 'end_time', 'schedule_type'], 'class_schedules_offering_block_unique');
            $table->index(['day_of_week', 'start_time', 'end_time', 'status'], 'class_schedules_weekly_scan_index');
            $table->index(['subject_offering_id', 'status']);
        });

        DB::statement('ALTER TABLE class_schedules ADD CONSTRAINT class_schedules_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE class_schedules ADD CONSTRAINT class_schedules_time_check CHECK (start_time < end_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
