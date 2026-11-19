<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicClassPreparationService
{
    private const PROGRAM_CODES = ['BSIT', 'BSCS'];

    public function __construct(private readonly CurriculumService $curriculum) {}

    public function plan(AcademicTerm $term): array
    {
        $programs = Program::query()
            ->whereIn('code', self::PROGRAM_CODES)
            ->get()
            ->keyBy('code');

        $sections = Section::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'active')
            ->whereHas('program', fn ($programs) => $programs->whereIn('code', self::PROGRAM_CODES))
            ->with([
                'program:id,code,name,status',
                'yearLevel:id,level,name',
                'major:id,program_id,code,name,status',
            ])
            ->orderBy('code')
            ->get();

        $issues = [];
        $warnings = [];
        $classes = [];
        $sectionsWithIssues = [];

        foreach (self::PROGRAM_CODES as $code) {
            $program = $programs->get($code);

            if ($program === null) {
                $issues[] = $this->diagnostic(
                    'program_missing',
                    "Program {$code} is missing and cannot be prepared.",
                    'blocking',
                    null,
                    $code,
                );
                continue;
            }

            if ($program->status !== 'active') {
                $issues[] = $this->diagnostic(
                    'program_inactive',
                    "Program {$code} is {$program->status}; its sections cannot be prepared.",
                    'blocking',
                    null,
                    $code,
                );
            }
        }

        if ($sections->isEmpty()) {
            $warnings[] = $this->diagnostic(
                'no_active_sections',
                'No active BSIT or BSCS sections exist for this academic term.',
                'warning',
            );
        }

        $existingOfferings = SubjectOffering::query()
            ->where('academic_term_id', $term->id)
            ->whereIn('section_id', $sections->pluck('id'))
            ->get(['id', 'section_id', 'subject_id', 'status'])
            ->keyBy(fn (SubjectOffering $offering): string => $offering->section_id.':'.$offering->subject_id);

        foreach ($sections as $section) {
            $program = $section->program;
            $yearLevel = $section->yearLevel;

            if ($program === null || $yearLevel === null) {
                $issues[] = $this->diagnostic(
                    'section_scope_incomplete',
                    "Section {$section->code} is missing its program or year level.",
                    'blocking',
                    $section,
                );
                $sectionsWithIssues[$section->id] = true;
                continue;
            }

            if ($program->status !== 'active') {
                continue;
            }

            if ($yearLevel->level < 1 || $yearLevel->level > 4) {
                $issues[] = $this->diagnostic(
                    'unsupported_year_level',
                    "Section {$section->code} uses year level {$yearLevel->level}; only first through fourth year are supported.",
                    'blocking',
                    $section,
                );
                $sectionsWithIssues[$section->id] = true;
                continue;
            }

            $requiresMajor = $program->code === 'BSIT' && $yearLevel->level >= 3;
            $major = null;

            if ($requiresMajor) {
                if ($section->major === null) {
                    $issues[] = $this->diagnostic(
                        'missing_major',
                        "BSIT section {$section->code} is in year {$yearLevel->level} but has no selected major.",
                        'blocking',
                        $section,
                    );
                    $sectionsWithIssues[$section->id] = true;
                    continue;
                }

                if ($section->major->status !== 'active') {
                    $issues[] = $this->diagnostic(
                        'major_inactive',
                        "The selected major {$section->major->code} for section {$section->code} is inactive.",
                        'blocking',
                        $section,
                    );
                    $sectionsWithIssues[$section->id] = true;
                    continue;
                }

                if ((int) $section->major->program_id !== (int) $program->id) {
                    $issues[] = $this->diagnostic(
                        'major_program_mismatch',
                        "The selected major {$section->major->code} does not belong to {$program->code}.",
                        'blocking',
                        $section,
                    );
                    $sectionsWithIssues[$section->id] = true;
                    continue;
                }

                $major = $section->major;
            } elseif ($section->major !== null) {
                $warnings[] = $this->diagnostic(
                    'major_not_required',
                    "Section {$section->code} has a major, but {$program->code} year {$yearLevel->level} uses the common/minor curriculum; the major will be ignored.",
                    'warning',
                    $section,
                );
            }

            $allSubjects = $this->curriculum->subjectsFor(
                $program,
                $yearLevel,
                $major,
                $term->term,
                activeOnly: false,
            );
            $subjects = $allSubjects
                ->filter(fn (Subject $subject): bool => $subject->status === 'active')
                ->values();
            $inactiveSubjects = $allSubjects
                ->filter(fn (Subject $subject): bool => $subject->status !== 'active')
                ->values();

            if ($subjects->isEmpty()) {
                $issues[] = $this->diagnostic(
                    'curriculum_missing',
                    "No active curriculum subjects are mapped for {$program->code} year {$yearLevel->level}, semester {$term->term}.".($major !== null ? " Major {$major->code}." : ''),
                    'blocking',
                    $section,
                );
                $sectionsWithIssues[$section->id] = true;
                continue;
            }

            if ($inactiveSubjects->isNotEmpty()) {
                $warnings[] = $this->diagnostic(
                    'subject_inactive',
                    "Section {$section->code} maps inactive subject(s) {$inactiveSubjects->pluck('code')->implode(', ')}. They will be skipped.",
                    'warning',
                    $section,
                );
            }

            foreach ($subjects as $subject) {
                $key = $section->id.':'.$subject->id;
                $existingOffering = $existingOfferings->get($key);

                $classes[] = [
                    'section_id' => $section->id,
                    'section_code' => $section->code,
                    'program_id' => $program->id,
                    'program_code' => $program->code,
                    'year_level_id' => $yearLevel->id,
                    'year_level' => $yearLevel->level,
                    'major_id' => $major?->id,
                    'major_code' => $major?->code,
                    'subject_id' => $subject->id,
                    'subject_code' => $subject->code,
                    'subject_title' => $subject->title,
                    'exists' => $existingOffering !== null,
                    'offering_id' => $existingOffering?->id,
                    'offering_status' => $existingOffering?->status,
                ];
            }
        }

        $requiredCount = count($classes);
        $existingCount = count(array_filter(
            $classes,
            fn (array $class): bool => $class['exists'],
        ));

        return [
            'academic_term' => [
                'id' => $term->id,
                'academic_year' => $term->academic_year,
                'term' => $term->term,
                'name' => $term->name,
                'status' => $term->status,
                'is_current' => $term->is_current,
            ],
            'summary' => [
                'required' => $requiredCount,
                'existing' => $existingCount,
                'missing' => $requiredCount - $existingCount,
                'sections' => $sections->count(),
                'sections_with_issues' => count($sectionsWithIssues),
                'issues' => count($issues),
                'warnings' => count($warnings),
            ],
            'classes' => $classes,
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    public function prepare(AcademicTerm $term): array
    {
        return DB::transaction(function () use ($term): array {
            $term = AcademicTerm::query()->lockForUpdate()->findOrFail($term->id);

            if ($term->status === 'archived') {
                throw ValidationException::withMessages([
                    'academic_term_id' => 'Choose a non-archived academic term.',
                ]);
            }

            $plan = $this->plan($term);
            $createdCount = 0;

            foreach ($plan['classes'] as $class) {
                if ($class['exists']) {
                    continue;
                }

                $offering = SubjectOffering::query()->firstOrCreate(
                    [
                        'academic_term_id' => $term->id,
                        'section_id' => $class['section_id'],
                        'subject_id' => $class['subject_id'],
                    ],
                    [
                        'expected_students' => null,
                        'status' => 'draft',
                    ],
                );

                if ($offering->wasRecentlyCreated) {
                    $createdCount++;
                }
            }

            return [
                'created' => $createdCount,
                'plan' => $this->plan($term),
            ];
        }, attempts: 3);
    }

    private function diagnostic(
        string $code,
        string $message,
        string $severity,
        ?Section $section = null,
        ?string $programCode = null,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'severity' => $severity,
            'section_id' => $section?->id,
            'section_code' => $section?->code,
            'program_code' => $section?->program?->code ?? $programCode,
            'year_level' => $section?->yearLevel?->level,
            'major_code' => $section?->major?->code,
        ];
    }
}
