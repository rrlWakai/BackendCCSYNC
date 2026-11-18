<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_qr_identities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('identifier', 64)->unique('room_qr_identifier_unique');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'revoked_at'], 'room_qr_room_revoked_index');
        });

        Schema::create('room_occupancies', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('scheduled_room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('professor_id')->constrained('professors')->restrictOnDelete();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->restrictOnDelete();
            $table->foreignId('schedule_assignment_id')->constrained('schedule_assignments')->restrictOnDelete();
            $table->foreignId('room_allocation_id')->constrained('room_allocations')->restrictOnDelete();
            $table->foreignId('room_qr_identity_id')->constrained('room_qr_identities')->restrictOnDelete();
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('class_date');
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->string('checkout_source', 24)->nullable();
            $table->string('mismatch_reason', 500)->nullable();
            $table->string('discrepancy_status', 16)->default('none');
            $table->timestamp('discrepancy_resolved_at')->nullable();
            $table->foreignId('discrepancy_resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('discrepancy_resolution_notes', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['schedule_assignment_id', 'class_date'],
                'room_occupancy_assignment_date_unique',
            );
            $table->index(
                ['room_id', 'checked_out_at', 'scheduled_end_at'],
                'room_occupancy_room_active_index',
            );
            $table->index(
                ['professor_id', 'checked_out_at', 'scheduled_end_at'],
                'room_occupancy_professor_active_index',
            );
            $table->index(['class_date', 'discrepancy_status'], 'room_occupancy_discrepancy_index');
        });

        Schema::create('room_occupancy_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('room_occupancy_id')->constrained('room_occupancies')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['room_occupancy_id', 'occurred_at'], 'room_occupancy_event_history_index');
        });
    }

    public function down(): void
    {
        throw new \LogicException(
            'Room monitoring data is historical and must not be deleted by rolling back this migration.',
        );
    }
};
