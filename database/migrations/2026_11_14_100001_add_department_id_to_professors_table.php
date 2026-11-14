<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professors', function (Blueprint $table) {
            $table->string('employee_number', 32)->nullable()->change();
            $table->foreignId('department_id')
                ->nullable()
                ->after('program_id')
                ->constrained('departments')
                ->restrictOnDelete();
        });

    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('professors')->whereNull('employee_number')->exists()) {
            throw new RuntimeException(
                'Cannot roll back professor employee numbers while self-registered profiles have no assigned number.',
            );
        }

        Schema::table('professors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->string('employee_number', 32)->nullable(false)->change();
        });
    }
};
