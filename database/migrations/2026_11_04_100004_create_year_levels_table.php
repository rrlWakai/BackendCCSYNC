<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_levels', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->string('name', 32);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE year_levels ADD CONSTRAINT year_levels_level_range_check CHECK (level BETWEEN 1 AND 6)');
    }

    public function down(): void
    {
        Schema::dropIfExists('year_levels');
    }
};
