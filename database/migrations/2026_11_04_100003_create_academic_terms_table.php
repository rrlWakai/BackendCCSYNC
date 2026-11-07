<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->char('academic_year', 9);
            $table->string('term', 16);
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->string('status', 16)->default('planned');
            $table->timestamps();

            $table->unique(['academic_year', 'term']);
            $table->index(['status', 'is_current']);
            $table->index(['starts_on', 'ends_on']);
        });

        DB::statement('ALTER TABLE academic_terms ADD CONSTRAINT academic_terms_date_order_check CHECK (starts_on <= ends_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
