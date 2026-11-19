<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_status_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_status_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }
};
