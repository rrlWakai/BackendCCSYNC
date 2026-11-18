<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('major_id')
                ->nullable()
                ->after('year_level_id')
                ->constrained('majors')
                ->restrictOnDelete();

            $table->index(['major_id', 'status'], 'sections_major_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('sections_major_status_index');
            $table->dropConstrainedForeignId('major_id');
        });
    }
};
