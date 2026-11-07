<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('code', 32)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->decimal('units', 4, 1);
            $table->decimal('lecture_hours', 4, 1)->default(0);
            $table->decimal('lab_hours', 4, 1)->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('title');
        });

        DB::statement('ALTER TABLE subjects ADD CONSTRAINT subjects_units_check CHECK (units > 0)');
        DB::statement('ALTER TABLE subjects ADD CONSTRAINT subjects_lecture_hours_check CHECK (lecture_hours >= 0)');
        DB::statement('ALTER TABLE subjects ADD CONSTRAINT subjects_lab_hours_check CHECK (lab_hours >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
