<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_major_selection_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_major_selection_id');
            $table->foreign('student_major_selection_id', 'smc_selection_foreign')
                ->references('id')
                ->on('student_major_selections')
                ->cascadeOnDelete();
            $table->foreignId('previous_major_id')->nullable()->constrained('majors')->nullOnDelete();
            $table->foreignId('new_major_id')->constrained('majors')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('student_major_selection_id', 'selection_changes_selection_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_major_selection_changes');
    }
};
