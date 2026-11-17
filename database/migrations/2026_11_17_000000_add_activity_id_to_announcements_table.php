<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->foreignId('activity_id')
                ->nullable()
                ->after('author_user_id')
                ->constrained('activities')
                ->restrictOnDelete();

            $table->index(['activity_id', 'created_at'], 'announcements_activity_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropIndex('announcements_activity_created_index');
            $table->dropForeign(['activity_id']);
            $table->dropColumn('activity_id');
        });
    }
};
