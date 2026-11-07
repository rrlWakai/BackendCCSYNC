<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->after('id')
                ->constrained('roles')
                ->restrictOnDelete();

            $table->string('status', 20)->default('active')->after('remember_token');
            $table->timestamp('last_login_at')->nullable()->after('status');

            $table->index(['role_id', 'status']);
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id', 'status']);
            $table->dropIndex(['last_login_at']);
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['status', 'last_login_at']);
        });
    }
};
