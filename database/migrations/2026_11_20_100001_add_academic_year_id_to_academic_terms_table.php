<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('academic_year')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->index('academic_year_id');
        });

        DB::table('academic_years')
            ->select('id', 'label')
            ->orderBy('id')
            ->each(function (object $academicYear): void {
                DB::table('academic_terms')
                    ->where('academic_year', $academicYear->label)
                    ->update(['academic_year_id' => $academicYear->id]);
            });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
