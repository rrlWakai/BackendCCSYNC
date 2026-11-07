<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('professor_id')->constrained('professors')->restrictOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->string('assignment_role', 20);
            $table->decimal('assigned_units', 4, 1)->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['professor_id', 'subject_offering_id', 'assignment_role'], 'faculty_assignment_role_unique');
            $table->index(['subject_offering_id', 'status']);
            $table->index(['professor_id', 'status']);
        });

        DB::statement('ALTER TABLE faculty_assignments ADD CONSTRAINT faculty_assignments_units_check CHECK (assigned_units IS NULL OR assigned_units >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_assignments');
    }
};
