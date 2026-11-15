<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professors', function (Blueprint $table) {
            $table->decimal('weekly_teaching_hours_quota', 4, 1)
                ->nullable()
                ->after('employment_type');
        });

        DB::statement('ALTER TABLE professors ADD CONSTRAINT professors_weekly_quota_check CHECK (weekly_teaching_hours_quota IS NULL OR weekly_teaching_hours_quota >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE professors DROP CHECK professors_weekly_quota_check');

        Schema::table('professors', function (Blueprint $table) {
            $table->dropColumn('weekly_teaching_hours_quota');
        });
    }
};
