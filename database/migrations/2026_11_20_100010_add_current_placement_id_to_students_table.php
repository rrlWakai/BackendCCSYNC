<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('current_placement_id')
                ->nullable()
                ->after('section_id')
                ->constrained('student_academic_placements')
                ->nullOnDelete();

            $table->unique('current_placement_id', 'students_current_placement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_current_placement_unique');
            $table->dropConstrainedForeignId('current_placement_id');
        });
    }
};
