<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_activity_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at'], 'student_activity_student_created_index');
            $table->index(['action', 'created_at'], 'student_activity_action_created_index');
        });

        Schema::create('student_removals', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('previous_user_status', 20);
            $table->string('previous_student_status', 16);
            $table->timestamp('removed_at');
            $table->timestamp('restore_deadline');
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('permanent_deletion_started_at')->nullable();
            $table->timestamp('permanently_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'student_removals_student_status_index');
            $table->index(['status', 'restore_deadline'], 'student_removals_status_deadline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_removals');
        Schema::dropIfExists('student_activity_logs');
    }
};
