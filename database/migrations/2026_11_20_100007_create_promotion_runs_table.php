<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('source_academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('target_academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('source_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('target_term_id')->nullable()->constrained('academic_terms')->restrictOnDelete();
            $table->string('status', 16)->default('prepared');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_academic_year_id', 'source_term_id'], 'promotion_runs_source_unique');
            $table->index(['status', 'target_academic_year_id'], 'promotion_runs_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_runs');
    }
};
