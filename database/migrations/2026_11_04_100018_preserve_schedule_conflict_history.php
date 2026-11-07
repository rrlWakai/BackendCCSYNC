<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_conflicts', function (Blueprint $table) {
            $table->dropForeign(['class_schedule_id']);
            $table->dropForeign(['conflicting_class_schedule_id']);

            $table->foreign('class_schedule_id')
                ->references('id')
                ->on('class_schedules')
                ->restrictOnDelete();
            $table->foreign('conflicting_class_schedule_id')
                ->references('id')
                ->on('class_schedules')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_conflicts', function (Blueprint $table) {
            $table->dropForeign(['class_schedule_id']);
            $table->dropForeign(['conflicting_class_schedule_id']);

            $table->foreign('class_schedule_id')
                ->references('id')
                ->on('class_schedules')
                ->cascadeOnDelete();
            $table->foreign('conflicting_class_schedule_id')
                ->references('id')
                ->on('class_schedules')
                ->cascadeOnDelete();
        });
    }
};
