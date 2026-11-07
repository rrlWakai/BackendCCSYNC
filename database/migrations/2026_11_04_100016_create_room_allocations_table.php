<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_allocations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('allocated_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['class_schedule_id', 'room_id']);
            $table->index(['room_id', 'status']);
            $table->index(['class_schedule_id', 'status']);
        });

        DB::statement('ALTER TABLE room_allocations ADD CONSTRAINT room_allocations_release_check CHECK (released_at IS NULL OR released_at >= allocated_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_allocations');
    }
};
