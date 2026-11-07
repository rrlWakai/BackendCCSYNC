<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_qr_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('token_hash', 'attendance_qr_tokens_hash_unique');
            $table->index(
                ['attendance_session_id', 'revoked_at', 'expires_at'],
                'attendance_qr_tokens_lookup_index',
            );
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->timestamp('qr_opened_at')->nullable()->after('submitted_at');
            $table->timestamp('qr_closed_at')->nullable()->after('qr_opened_at');
        });

        Schema::table('student_attendance', function (Blueprint $table) {
            $table->string('submission_source', 16)->default('manual')->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('student_attendance', function (Blueprint $table) {
            $table->dropColumn('submission_source');
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn(['qr_opened_at', 'qr_closed_at']);
        });

        Schema::dropIfExists('attendance_qr_tokens');
    }
};
