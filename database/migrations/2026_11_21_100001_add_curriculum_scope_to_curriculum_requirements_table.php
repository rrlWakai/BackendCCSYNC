<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_requirements', function (Blueprint $table) {
            $table->dropUnique('curriculum_requirements_scope_unique');
        });

        Schema::table('curriculum_requirements', function (Blueprint $table) {
            $table->string('semester', 16)->nullable()->after('year_level_id');
            $table->string('classification', 16)->default('common')->after('major_id');

            $table->unique(
                ['program_id', 'year_level_id', 'semester', 'major_id', 'subject_id'],
                'curriculum_requirements_scope_unique',
            );
            $table->index(
                ['program_id', 'year_level_id', 'semester', 'status'],
                'curriculum_requirements_term_index',
            );
        });

        DB::table('curriculum_requirements')
            ->whereNull('major_id')
            ->update(['classification' => 'common']);

        DB::table('curriculum_requirements')
            ->whereNotNull('major_id')
            ->update(['classification' => 'major']);
    }

    public function down(): void
    {
        Schema::table('curriculum_requirements', function (Blueprint $table) {
            $table->dropUnique('curriculum_requirements_scope_unique');
            $table->dropIndex('curriculum_requirements_term_index');
        });

        Schema::table('curriculum_requirements', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'year_level_id', 'major_id', 'subject_id'],
                'curriculum_requirements_scope_unique',
            );

            $table->dropColumn(['semester', 'classification']);
        });
    }
};
