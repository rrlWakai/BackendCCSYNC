<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('year_level_id')->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 80)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['academic_term_id', 'code']);
            $table->index(['program_id', 'year_level_id']);
            $table->index(['academic_term_id', 'status']);
        });

        DB::statement('ALTER TABLE sections ADD CONSTRAINT sections_capacity_check CHECK (capacity IS NULL OR capacity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
