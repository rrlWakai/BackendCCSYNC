<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use Illuminate\Support\Facades\DB;

final class PlacementService
{
    public function __construct(private readonly AcademicYearService $academicYears) {}

    public function currentFor(Student $student): ?StudentAcademicPlacement
    {
        if ($student->current_placement_id !== null) {
            $placement = StudentAcademicPlacement::query()
                ->with(['academicYear', 'academicTerm', 'program', 'yearLevel', 'major', 'section'])
                ->find($student->current_placement_id);

            if ($placement !== null) {
                return $placement;
            }
        }

        return StudentAcademicPlacement::query()
            ->with(['academicYear', 'academicTerm', 'program', 'yearLevel', 'major', 'section'])
            ->where('student_id', $student->id)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    public function createInitialPlacement(
        Student $student,
        Section $section,
        ?AcademicTerm $term = null,
    ): StudentAcademicPlacement {
        return DB::transaction(function () use ($student, $section, $term): StudentAcademicPlacement {
            $term ??= $section->academicTerm()->firstOrFail();
            $academicYear = $this->academicYears->resolveForTerm($term);

            $placement = StudentAcademicPlacement::query()->firstOrCreate(
                [
                    'student_id' => $student->id,
                    'academic_term_id' => $term->id,
                ],
                [
                    'academic_year_id' => $academicYear->id,
                    'program_id' => $section->program_id,
                    'year_level_id' => $section->year_level_id,
                    'major_id' => $section->major_id,
                    'section_id' => $section->id,
                    'status' => 'active',
                    'is_current' => true,
                    'effective_on' => $term->starts_on?->toDateString(),
                ],
            );

            StudentAcademicPlacement::query()
                ->where('student_id', $student->id)
                ->whereKeyNot($placement->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            if (! $placement->is_current) {
                $placement->update(['is_current' => true]);
            }

            $student->forceFill([
                'current_placement_id' => $placement->id,
                'section_id' => $section->id,
            ])->save();

            return $placement->refresh()->load([
                'academicYear',
                'academicTerm',
                'program',
                'yearLevel',
                'major',
                'section',
            ]);
        });
    }

    /**
     * @return array{created: int, skipped: int, missing_section: int}
     */
    public function backfill(): array
    {
        $created = 0;
        $skipped = 0;
        $missingSection = 0;

        Student::query()
            ->whereNull('current_placement_id')
            ->orderBy('id')
            ->each(function (Student $student) use (&$created, &$skipped, &$missingSection): void {
                if ($student->section_id === null) {
                    $missingSection++;

                    return;
                }

                $section = Section::query()
                    ->with(['academicTerm', 'program', 'yearLevel'])
                    ->find($student->section_id);

                if ($section === null || $section->academicTerm === null) {
                    $missingSection++;

                    return;
                }

                $term = $section->academicTerm;
                $academicYear = $this->academicYears->resolveForTerm($term);

                $placement = StudentAcademicPlacement::query()->firstOrCreate(
                    [
                        'student_id' => $student->id,
                        'academic_term_id' => $term->id,
                    ],
                    [
                        'academic_year_id' => $academicYear->id,
                        'program_id' => $section->program_id,
                        'year_level_id' => $section->year_level_id,
                        'major_id' => $section->major_id,
                        'section_id' => $section->id,
                        'status' => 'active',
                        'is_current' => true,
                        'effective_on' => $term->starts_on?->toDateString(),
                    ],
                );

                if (! $placement->wasRecentlyCreated) {
                    $skipped++;

                    return;
                }

                StudentAcademicPlacement::query()
                    ->where('student_id', $student->id)
                    ->whereKeyNot($placement->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                $student->forceFill(['current_placement_id' => $placement->id])->save();
                $created++;
            });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'missing_section' => $missingSection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(StudentAcademicPlacement $placement): array
    {
        $placement->loadMissing(['academicYear', 'academicTerm', 'program', 'yearLevel', 'major', 'section']);

        return [
            'id' => $placement->id,
            'status' => $placement->status,
            'is_current' => $placement->is_current,
            'effective_on' => $placement->effective_on?->toDateString(),
            'ends_on' => $placement->ends_on?->toDateString(),
            'academic_year' => $placement->academicYear === null ? null : [
                'id' => $placement->academicYear->id,
                'label' => $placement->academicYear->label,
            ],
            'academic_term' => $placement->academicTerm === null ? null : [
                'id' => $placement->academicTerm->id,
                'academic_year' => $placement->academicTerm->academic_year,
                'term' => $placement->academicTerm->term,
                'name' => $placement->academicTerm->name,
            ],
            'program' => $placement->program === null ? null : [
                'id' => $placement->program->id,
                'code' => $placement->program->code,
                'name' => $placement->program->name,
            ],
            'year_level' => $placement->yearLevel === null ? null : [
                'id' => $placement->yearLevel->id,
                'level' => $placement->yearLevel->level,
                'name' => $placement->yearLevel->name,
            ],
            'major' => $placement->major === null ? null : [
                'id' => $placement->major->id,
                'code' => $placement->major->code,
                'name' => $placement->major->name,
            ],
            'section' => $placement->section === null ? null : [
                'id' => $placement->section->id,
                'code' => $placement->section->code,
                'name' => $placement->section->name,
            ],
        ];
    }
}
