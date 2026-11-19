<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_full_time_workday_policies', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('standard_days');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'version'], 'scheduling_full_time_policies_term_version_unique');
            $table->index(['academic_term_id', 'status'], 'scheduling_full_time_policies_term_status_index');
        });

        Schema::create('scheduling_full_time_workday_exceptions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('policy_id')->constrained('scheduling_full_time_workday_policies')->cascadeOnDelete();
            $table->foreignId('professor_id')->constrained('professors')->restrictOnDelete();
            $table->json('authorized_additional_days');
            $table->timestamps();

            $table->unique(['policy_id', 'professor_id'], 'scheduling_full_time_exceptions_policy_professor_unique');
            $table->index('professor_id', 'scheduling_full_time_exceptions_professor_index');
        });

        DB::statement('ALTER TABLE scheduling_full_time_workday_policies ADD CONSTRAINT scheduling_full_time_policies_standard_days_check CHECK (json_valid(standard_days) AND JSON_LENGTH(standard_days) BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE scheduling_full_time_workday_exceptions ADD CONSTRAINT scheduling_full_time_exceptions_days_check CHECK (json_valid(authorized_additional_days) AND JSON_LENGTH(authorized_additional_days) BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_full_time_workday_exceptions');
        Schema::dropIfExists('scheduling_full_time_workday_policies');
    }
};
