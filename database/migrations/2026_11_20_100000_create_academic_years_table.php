<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->char('label', 9)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('planned');
            $table->timestamps();

            $table->index('status');
            $table->index(['starts_on', 'ends_on']);
        });

        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT academic_years_date_order_check CHECK (starts_on <= ends_on)');

        $labels = DB::table('academic_terms')
            ->select('academic_year')
            ->distinct()
            ->orderBy('academic_year')
            ->pluck('academic_year');

        foreach ($labels as $label) {
            $terms = DB::table('academic_terms')->where('academic_year', $label);

            $startsOn = (clone $terms)->min('starts_on');
            $endsOn = (clone $terms)->max('ends_on');
            $hasActive = (clone $terms)->where(function ($query): void {
                $query->where('is_current', true)->orWhere('status', 'active');
            })->exists();

            $allCompleted = ! (clone $terms)->whereNotIn('status', ['completed', 'archived'])->exists();

            DB::table('academic_years')->updateOrInsert(
                ['label' => $label],
                [
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => $hasActive ? 'active' : ($allCompleted ? 'completed' : 'planned'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
