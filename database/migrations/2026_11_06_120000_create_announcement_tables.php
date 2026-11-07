<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->text('content');
            $table->string('category', 32)->default('general');
            $table->string('priority', 16)->default('normal');
            $table->string('audience_scope', 16)->default('all');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at', 'expires_at'], 'announcements_visibility_index');
            $table->index(['audience_scope', 'status'], 'announcements_audience_status_index');
            $table->index(['category', 'status'], 'announcements_category_status_index');
            $table->index(['author_user_id', 'created_at'], 'announcements_author_created_index');
        });

        Schema::create('announcement_targets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->restrictOnDelete();
            $table->foreignId('year_level_id')->nullable()->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['announcement_id', 'role_id'], 'announcement_target_role_unique');
            $table->unique(['announcement_id', 'program_id'], 'announcement_target_program_unique');
            $table->unique(['announcement_id', 'year_level_id'], 'announcement_target_year_level_unique');
            $table->unique(['announcement_id', 'section_id'], 'announcement_target_section_unique');
            $table->unique(['announcement_id', 'user_id'], 'announcement_target_user_unique');
            $table->index('role_id', 'announcement_target_role_index');
            $table->index('program_id', 'announcement_target_program_index');
            $table->index('year_level_id', 'announcement_target_year_level_index');
            $table->index('section_id', 'announcement_target_section_index');
            $table->index('user_id', 'announcement_target_user_index');
        });

        Schema::create('announcement_history', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->restrictOnDelete();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 16);
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['announcement_id', 'created_at'], 'announcement_history_announcement_index');
            $table->index(['action', 'created_at'], 'announcement_history_action_index');
            $table->index('acted_by_user_id', 'announcement_history_actor_index');
        });

        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_category_check CHECK (category IN ('general', 'academic', 'class_suspension', 'examination', 'department', 'event', 'advisory'))");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_priority_check CHECK (priority IN ('normal', 'important', 'urgent'))");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_audience_scope_check CHECK (audience_scope IN ('all', 'custom'))");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_archived_at_check CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_expiry_check CHECK (expires_at IS NULL OR published_at IS NULL OR expires_at > published_at)');
        DB::statement('ALTER TABLE announcement_targets ADD CONSTRAINT announcement_targets_one_dimension_check CHECK ((role_id IS NOT NULL) + (program_id IS NOT NULL) + (year_level_id IS NOT NULL) + (section_id IS NOT NULL) + (user_id IS NOT NULL) = 1)');
        DB::statement("ALTER TABLE announcement_history ADD CONSTRAINT announcement_history_action_check CHECK (action IN ('created', 'updated', 'published', 'archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_history');
        Schema::dropIfExists('announcement_targets');
        Schema::dropIfExists('announcements');
    }
};
