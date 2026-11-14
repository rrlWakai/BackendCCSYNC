<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        DB::table('departments')->insert([
            'code' => 'CCS',
            'name' => 'College of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
