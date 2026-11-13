<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\RoomAllocation;
use App\Models\Section;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubjectOfferingManagementService
{
    private const DIRECTORY_RELATIONS = [
        'academicTerm:id,academic_year,term,name,status,starts_on,ends_on',
        'section:id,program_id,year_level_id,academic_term_id,code,name,capacity,status',
        'section.program:id,code,name,status',
        'section.yearLevel:id,level,name',
        'subject:id,code,title,units,status',
    ];

    private const DETAIL_RELATIONS = [
        'academicTerm:id,academic_year,term,name,status,starts_on,ends_on',
        'section:id,program_id,year_level_id,academic_term_id,code,name,capacity,status',
        'section.program:id,code,name,status',
        'section.yearLevel:id,level,name',
        'subject:id,code,title,units,status',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<SubjectOffering>, pagination: array<string, int>}
     */
    public function directory(array $filters): array
    {
        $query = SubjectOffering::query()
            ->with(self::DIRECTORY_RELATIONS)
            ->withCount([
                'enrollments as enrolled_students_count' => fn (Builder $enrollments) => $enrollments
                    ->where('status', 'enrolled'),
                'facultyAssignments as active_faculty_assignments_count' => fn (Builder $assignments) => $assignments
                    ->where('status', 'active'),
                'classSchedules as published_schedules_count' => fn (Builder $schedules) => $schedules
                    ->where('status', 'published'),
            ]);

        foreach (['status', 'academic_term_id', 'section_id', 'subject_id'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (! empty($filters['program_id'])) {
            $query->whereHas('section', fn (Builder $sections) => $sections
                ->where('program_id', $filters['program_id']));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->whereHas('subject', fn (Builder $subjects) => $subjects
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('section', fn (Builder $sections) => $sections
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('academicTerm', fn (Builder $terms) => $terms
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('academic_year', 'like', '%'.$search.'%'));
            });
        }

        $paginator = $query
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SubjectOffering
    {
        return DB::transaction(function () use ($attributes): SubjectOffering {
            $term = AcademicTerm::query()->lockForUpdate()->findOrFail($attributes['academic_term_id']);
            $section = Section::query()->lockForUpdate()->findOrFail($attributes['section_id']);

            $this->assertTermCanReceiveOfferings($term);
            $this->assertTermMatchesSection((int) $term->id, (int) $section->id);
            $this->assertCombinationAvailable(
                (int) $term->id,
                (int) $section->id,
                (int) $attributes['subject_id'],
            );

            $offering = SubjectOffering::create([
                'academic_term_id' => $term->id,
                'section_id' => $section->id,
                'subject_id' => (int) $attributes['subject_id'],
                'expected_students' => $attributes['expected_students'] ?? null,
                'status' => 'draft',
            ]);

            return $this->loadDetail($offering);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SubjectOffering $subjectOffering, array $attributes): SubjectOffering
    {
        return DB::transaction(function () use ($subjectOffering, $attributes): SubjectOffering {
            $offering = SubjectOffering::query()->lockForUpdate()->findOrFail($subjectOffering->id);

            $this->assertEditable($offering, $attributes);
            $this->assertStructureRules($offering, $attributes);

            if (array_key_exists('expected_students', $attributes)) {
                $this->assertExpectedStudentsSafe($offering, $attributes['expected_students']);
            }

            $offering->update($attributes);

            return $this->loadDetail($offering->fresh());
        }, attempts: 3);
    }

    public function changeStatus(SubjectOffering $subjectOffering, string $status): SubjectOffering
    {
        return DB::transaction(function () use ($subjectOffering, $status): SubjectOffering {
            $offering = SubjectOffering::query()
                ->with(['academicTerm', 'section.program', 'subject'])
                ->lockForUpdate()
                ->findOrFail($subjectOffering->id);

            if ($offering->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Cancelled offerings are read-only and cannot be reopened.',
                ]);
            }

            if ($status === 'open') {
                if ($offering->status === 'open') {
                    throw ValidationException::withMessages([
                        'status' => 'This offering is already open.',
                    ]);
                }

                if (! in_array($offering->status, ['draft', 'closed'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Only draft or closed offerings can be opened.',
                    ]);
                }

                $this->assertEligibleToOpen($offering);
            }

            if ($status === 'closed') {
                if ($offering->status === 'closed') {
                    throw ValidationException::withMessages([
                        'status' => 'This offering is already closed.',
                    ]);
                }

                if ($offering->status !== 'open') {
                    throw ValidationException::withMessages([
                        'status' => 'Only open offerings can be closed.',
                    ]);
                }
            }

            $offering->update(['status' => $status]);

            return $this->loadDetail($offering->fresh());
        }, attempts: 3);
    }

    public function loadDetail(SubjectOffering $offering): SubjectOffering
    {
        return $offering
            ->load(array_merge(self::DETAIL_RELATIONS, [
                'facultyAssignments' => fn (Relation $assignments) => $assignments
                    ->where('status', 'active')
                    ->with([
                        'professor:id,user_id,employee_number,status',
                        'professor.user:id,name,status',
                    ])
                    ->orderBy('id'),
                'classSchedules' => fn (Relation $schedules) => $schedules
                    ->with([
                        'roomAllocations' => fn (Relation $allocations) => $allocations
                            ->where('status', 'active')
                            ->with('room:id,code,name,capacity')
                            ->orderBy('id'),
                    ])
                    ->orderBy('id'),
            ]))
            ->loadCount([
                'enrollments as enrolled_students_count' => fn (Builder $enrollments) => $enrollments
                    ->where('status', 'enrolled'),
                'facultyAssignments as active_faculty_assignments_count' => fn (Builder $assignments) => $assignments
                    ->where('status', 'active'),
                'classSchedules as published_schedules_count' => fn (Builder $schedules) => $schedules
                    ->where('status', 'published'),
            ]);
    }

    private function assertEditable(SubjectOffering $offering, array $attributes): void
    {
        if ($offering->status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => 'Cancelled offerings are read-only.',
            ]);
        }

        if ($offering->status === 'closed' && $attributes !== []) {
            throw ValidationException::withMessages([
                'status' => 'Closed offerings are read-only. Reopen the offering before making changes.',
            ]);
        }

        if ($offering->status !== 'open') {
            return;
        }

        foreach (['academic_term_id', 'section_id', 'subject_id'] as $field) {
            if (
                array_key_exists($field, $attributes)
                && (int) $attributes[$field] !== (int) $offering->getAttribute($field)
            ) {
                throw ValidationException::withMessages([
                    $field => 'Only expected students can be changed while the offering is open.',
                ]);
            }
        }
    }

    private function assertStructureRules(SubjectOffering $offering, array $attributes): void
    {
        $structuralFields = ['academic_term_id', 'section_id', 'subject_id'];

        if (! array_intersect($structuralFields, array_keys($attributes))) {
            return;
        }

        $termId = (int) ($attributes['academic_term_id'] ?? $offering->academic_term_id);
        $sectionId = (int) ($attributes['section_id'] ?? $offering->section_id);
        $subjectId = (int) ($attributes['subject_id'] ?? $offering->subject_id);

        if ($termId !== (int) $offering->academic_term_id) {
            $term = AcademicTerm::query()->findOrFail($termId);
            $this->assertTermCanReceiveOfferings($term);
        }

        $this->assertTermMatchesSection($termId, $sectionId);
        $this->assertCombinationAvailable($termId, $sectionId, $subjectId, (int) $offering->id);
    }

    private function assertTermCanReceiveOfferings(AcademicTerm $term): void
    {
        if ($term->status === 'archived') {
            throw ValidationException::withMessages([
                'academic_term_id' => 'Choose a non-archived academic term.',
            ]);
        }
    }

    private function assertTermMatchesSection(int $termId, int $sectionId): void
    {
        $section = Section::query()->findOrFail($sectionId);

        if ((int) $section->academic_term_id !== $termId) {
            throw ValidationException::withMessages([
                'academic_term_id' => 'The offering term must match the selected section term.',
            ]);
        }
    }

    private function assertCombinationAvailable(
        int $termId,
        int $sectionId,
        int $subjectId,
        ?int $ignoreId = null,
    ): void {
        $query = SubjectOffering::query()
            ->where('academic_term_id', $termId)
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'subject_id' => 'This section already offers the selected subject in the chosen term.',
            ]);
        }
    }

    private function assertExpectedStudentsSafe(SubjectOffering $offering, mixed $expectedStudents): void
    {
        if ($expectedStudents === null) {
            return;
        }

        $expectedStudents = (int) $expectedStudents;

        if ($expectedStudents < 0) {
            throw ValidationException::withMessages([
                'expected_students' => 'Expected students cannot be negative.',
            ]);
        }

        $enrolledStudents = $offering->enrollments()
            ->where('status', 'enrolled')
            ->count();

        if ($expectedStudents < $enrolledStudents) {
            throw ValidationException::withMessages([
                'expected_students' => "Expected students cannot be lower than the {$enrolledStudents} currently enrolled student(s).",
            ]);
        }

        $allocations = RoomAllocation::query()
            ->where('status', 'active')
            ->whereHas('classSchedule', fn (Builder $schedules) => $schedules
                ->where('subject_offering_id', $offering->id)
                ->where('status', 'published'))
            ->with([
                'room:id,code,name,capacity',
                'classSchedule:id,status',
            ])
            ->get();

        foreach ($allocations as $allocation) {
            if ($allocation->room === null || $allocation->room->capacity >= $expectedStudents) {
                continue;
            }

            throw ValidationException::withMessages([
                'expected_students' => sprintf(
                    'Expected students cannot exceed the capacity of room %s (%d seats) used by published schedule #%d.',
                    $allocation->room->code,
                    $allocation->room->capacity,
                    $allocation->class_schedule_id,
                ),
            ]);
        }
    }

    private function assertEligibleToOpen(SubjectOffering $offering): void
    {
        if (
            $offering->section->status !== 'active'
            || $offering->section->program->status !== 'active'
            || $offering->subject->status !== 'active'
            || ! in_array($offering->academicTerm->status, ['active', 'planned'], true)
        ) {
            throw ValidationException::withMessages([
                'status' => 'The offering, section, program, subject, and term must be active or planned before it can be opened.',
            ]);
        }

        if ((int) $offering->section->academic_term_id !== (int) $offering->academic_term_id) {
            throw ValidationException::withMessages([
                'status' => 'The offering term must match its section term before it can be opened.',
            ]);
        }
    }
}
