<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professors', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('employee_number', 32)->unique();
            $table->foreignId('program_id')->nullable()->constrained('programs')->restrictOnDelete();
            $table->string('employment_type', 20);
            $table->string('specialization', 160)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['program_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professors');
    }
};
