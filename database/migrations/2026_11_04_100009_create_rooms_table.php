<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('building', 100)->nullable();
            $table->string('floor', 16)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->string('room_type', 20);
            $table->string('status', 20)->default('available');
            $table->timestamps();

            $table->index(['building', 'floor']);
            $table->index(['status', 'room_type']);
        });

        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
