<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professor_registration_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('account_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->json('registration_snapshot')->nullable();
            $table->timestamps();

            $table->index(['account_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professor_registration_reviews');
    }
};
