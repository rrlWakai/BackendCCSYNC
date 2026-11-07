<?php

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class StudentScheduleConflictService
{
    private const DAY_LABELS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function options(User $actor, ?int $termId = null): array
    {
        $term = $termId === null
            ? $this->currentTerm()
            : AcademicTerm::query()->findOrFail($termId);
        $programIds = $this->authorizedProgramIds($actor);

        $sections = $term === null
            ? collect()
            : Section::query()
                ->where('academic_term_id', $term->id)
                ->where('status', 'active')
                ->when($programIds !== null, fn (Builder $query) => $query->whereIn('program_id', $programIds))
                ->with(['program:id,code,name', 'yearLevel:id,level,name'])
                ->orderBy('code')
                ->get();

        return [
            'terms' => AcademicTerm::query()
                ->whereIn('status', ['active', 'planned'])
                ->orderByDesc('is_current')
                ->orderByDesc('starts_on')
                ->get(['id', 'academic_year', 'term', 'name', 'status', 'is_current']),
            'selected_term' => $term === null ? null : $this->termSummary($term),
            'programs' => $sections
                ->pluck('program')
                ->unique('id')
                ->values()
                ->map(fn (Program $program): array => [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                ]),
            'year_levels' => $sections
                ->pluck('yearLevel')
                ->filter()
                ->unique('id')
                ->sortBy('level')
                ->values()
                ->map(fn ($yearLevel): array => [
                    'id' => $yearLevel->id,
                    'level' => $yearLevel->level,
                    'name' => $yearLevel->name,
                ]),
            'sections' => $sections
                ->map(fn (Section $section): array => [
                    'id' => $section->id,
                    'code' => $section->code,
                    'name' => $section->name,
                    'program_id' => $section->program_id,
                    'year_level_id' => $section->year_level_id,
                ]),
        ];
    }

    public function administrativePage(array $filters, User $actor): array
    {
        $term = $this->resolveTerm($filters['academic_term_id'] ?? null);
        if ($term === null) {
            return $this->emptyPage($filters, null);
        }

        $allowedProgramIds = $this->authorizedProgramIds($actor);
        $this->assertProgramFilterAllowed($filters, $allowedProgramIds);

        $students = $this->studentsQuery($term, $allowedProgramIds, $filters, includeCandidateFilters: true)
            ->with([
                'user:id,name,status',
                'program:id,code,name',
                'enrollments' => fn (HasMany $query) => $this->eligibleEnrollments($query, $term, $allowedProgramIds),
                ...$this->enrollmentRelations(),
            ])
            ->orderBy('students.student_number')
            ->get();

        $conflicts = $this->findConflicts($students, $term)
            ->filter(fn (array $conflict): bool => $this->matchesPairFilters($conflict, $filters))
            ->values();

        return $this->page($conflicts, $students->count(), $term, $filters);
    }

    public function ownPage(Student $student): array
    {
        $term = $this->currentTerm();
        if ($term === null || $student->status !== 'active') {
            return $this->emptyPage([], $term);
        }

        $student->load([
            'user:id,name,status',
            'program:id,code,name',
            'enrollments' => fn (HasMany $query) => $this->eligibleEnrollments($query, $term, null),
            ...$this->enrollmentRelations(),
        ]);
        $conflicts = $this->findConflicts(collect([$student]), $term)->values();

        return $this->page(
            $conflicts,
            $student->enrollments->isEmpty() ? 0 : 1,
            $term,
            ['page' => 1, 'per_page' => max(1, $conflicts->count())],
        );
    }

    private function emptyPage(array $filters, ?AcademicTerm $term): array
    {
        return $this->page(
            collect(),
            0,
            $term,
            $filters,
        );
    }

    private function page(Collection $conflicts, int $studentsChecked, ?AcademicTerm $term, array $filters): array
    {
        $studentIds = $conflicts->pluck('student.id')->unique();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $paginator = new LengthAwarePaginator(
            $conflicts->forPage($page, $perPage)->values(),
            $conflicts->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        return [
            'academic_term' => $term === null ? null : $this->termSummary($term),
            'summary' => [
                'students_checked' => $studentsChecked,
                'students_with_conflicts' => $studentIds->count(),
                'conflict_pairs' => $conflicts->count(),
            ],
            'conflicts' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function studentsQuery(
        AcademicTerm $term,
        ?array $allowedProgramIds,
        array $filters,
        bool $includeCandidateFilters,
    ): Builder {
        $query = Student::query()
            ->where('students.status', 'active')
            ->whereHas('user', fn (Builder $user) => $user
                ->where('status', 'active')
                ->whereHas('role', fn (Builder $role) => $role->where('code', Role::STUDENT)))
            ->whereHas('enrollments', fn (Builder $enrollments) => $this->eligibleEnrollments(
                $enrollments,
                $term,
                $allowedProgramIds,
                $includeCandidateFilters ? $filters : [],
            ));

        if ($allowedProgramIds !== null) {
            $query->whereIn('students.program_id', $allowedProgramIds);
        }
        if (! empty($filters['program_id'])) {
            $query->where('students.program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('students.student_number', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%'));
            });
        }

        return $query;
    }

    private function eligibleEnrollments(
        Builder|HasMany $query,
        AcademicTerm $term,
        ?array $allowedProgramIds,
        array $filters = [],
    ): Builder|HasMany {
        return $query
            ->where('status', 'enrolled')
            ->whereHas('subjectOffering', function (Builder $offering) use ($term, $allowedProgramIds, $filters): void {
                $offering
                    ->where('academic_term_id', $term->id)
                    ->where('status', 'open')
                    ->whereHas('subject', fn (Builder $subject) => $subject->where('status', 'active'))
                    ->whereHas('section', function (Builder $section) use ($term, $allowedProgramIds, $filters): void {
                        $section->where('academic_term_id', $term->id)
                            ->where('status', 'active');
                        if ($allowedProgramIds !== null) {
                            $section->whereIn('program_id', $allowedProgramIds);
                        }
                        if (! empty($filters['section_id'])) {
                            $section->whereKey((int) $filters['section_id']);
                        }
                        if (! empty($filters['year_level_id'])) {
                            $section->where('year_level_id', (int) $filters['year_level_id']);
                        }
                    });
            });
    }

    private function enrollmentRelations(): array
    {
        return [
            'enrollments.subjectOffering.subject:id,code,title',
            'enrollments.subjectOffering.academicTerm:id,academic_year,term,name',
            'enrollments.subjectOffering.section:id,code,name,program_id,year_level_id,academic_term_id',
            'enrollments.subjectOffering.section.program:id,code,name',
            'enrollments.subjectOffering.section.yearLevel:id,level,name',
            'enrollments.subjectOffering.classSchedules' => fn (HasMany $query) => $query->where('status', 'published'),
            'enrollments.subjectOffering.classSchedules.scheduleAssignments' => fn (HasMany $query) => $query
                ->where('status', 'active')
                ->whereHas('facultyAssignment', fn (Builder $faculty) => $faculty
                    ->where('status', 'active')
                    ->whereHas('professor', fn (Builder $professor) => $professor
                        ->where('status', 'active')
                        ->whereHas('user', fn (Builder $user) => $user->where('status', 'active')))),
            'enrollments.subjectOffering.classSchedules.scheduleAssignments.facultyAssignment.professor.user:id,name,status',
            'enrollments.subjectOffering.classSchedules.roomAllocations' => fn (HasMany $query) => $query->where('status', 'active'),
            'enrollments.subjectOffering.classSchedules.roomAllocations.room:id,code,name,building',
        ];
    }

    private function findConflicts(Collection $students, AcademicTerm $term): Collection
    {
        $results = collect();

        foreach ($students as $student) {
            $rows = $student->enrollments
                ->flatMap(function (StudentSubjectEnrollment $enrollment): array {
                    $offering = $enrollment->subjectOffering;

                    return $offering->classSchedules
                        ->map(fn ($schedule): array => [
                            'schedule' => $schedule,
                            'offering' => $offering,
                            'subject' => $offering->subject,
                            'section' => $offering->section,
                        ])
                        ->all();
                })
                ->values();
            $seenPairs = [];

            for ($leftIndex = 0; $leftIndex < $rows->count(); $leftIndex++) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $rows->count(); $rightIndex++) {
                    $left = $rows[$leftIndex];
                    $right = $rows[$rightIndex];
                    $leftSchedule = $left['schedule'];
                    $rightSchedule = $right['schedule'];

                    if ($left['subject']->id === $right['subject']->id
                        || $leftSchedule->subject_offering_id === $rightSchedule->subject_offering_id
                        || $leftSchedule->day_of_week !== $rightSchedule->day_of_week) {
                        continue;
                    }

                    $leftStart = substr((string) $leftSchedule->start_time, 0, 8);
                    $leftEnd = substr((string) $leftSchedule->end_time, 0, 8);
                    $rightStart = substr((string) $rightSchedule->start_time, 0, 8);
                    $rightEnd = substr((string) $rightSchedule->end_time, 0, 8);
                    if ($leftStart >= $rightEnd || $rightStart >= $leftEnd) {
                        continue;
                    }

                    $pairKey = min($leftSchedule->id, $rightSchedule->id).':'.max($leftSchedule->id, $rightSchedule->id);
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;

                    $classes = [
                        $this->classSummary($left),
                        $this->classSummary($right),
                    ];
                    usort($classes, fn (array $first, array $second): int => [
                        $first['subject']['code'],
                        $first['start_time'],
                        $first['schedule_id'],
                    ] <=> [
                        $second['subject']['code'],
                        $second['start_time'],
                        $second['schedule_id'],
                    ]);

                    $results->push([
                        'id' => $student->id.'-'.$pairKey,
                        'student' => [
                            'id' => $student->id,
                            'name' => $student->user?->name,
                            'student_number' => $student->student_number,
                            'program' => $student->program === null ? null : [
                                'id' => $student->program->id,
                                'code' => $student->program->code,
                                'name' => $student->program->name,
                            ],
                            'year_levels' => $student->enrollments
                                ->map(fn (StudentSubjectEnrollment $enrollment) => $enrollment->subjectOffering->section->yearLevel)
                                ->filter()
                                ->unique('id')
                                ->sortBy('level')
                                ->values()
                                ->map(fn ($yearLevel): array => [
                                    'id' => $yearLevel->id,
                                    'level' => $yearLevel->level,
                                    'name' => $yearLevel->name,
                                ])
                                ->all(),
                        ],
                        'academic_term' => $this->termSummary($term),
                        'day_of_week' => $leftSchedule->day_of_week,
                        'day_label' => self::DAY_LABELS[$leftSchedule->day_of_week] ?? 'Unknown day',
                        'overlap' => [
                            'start_time' => max($leftStart, $rightStart),
                            'end_time' => min($leftEnd, $rightEnd),
                        ],
                        'classes' => $classes,
                    ]);
                }
            }
        }

        return $results
            ->sortBy(fn (array $conflict): string => sprintf(
                '%s|%s|%s|%s',
                $conflict['student']['student_number'],
                str_pad((string) $conflict['day_of_week'], 2, '0', STR_PAD_LEFT),
                $conflict['overlap']['start_time'],
                $conflict['id'],
            ))
            ->values();
    }

    private function classSummary(array $row): array
    {
        $schedule = $row['schedule'];
        $section = $row['section'];
        $room = $schedule->roomAllocations->first()?->room;

        return [
            'schedule_id' => $schedule->id,
            'subject_offering_id' => $row['offering']->id,
            'subject' => [
                'id' => $row['subject']->id,
                'code' => $row['subject']->code,
                'title' => $row['subject']->title,
            ],
            'section' => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'program' => $section->program === null ? null : [
                    'id' => $section->program->id,
                    'code' => $section->program->code,
                    'name' => $section->program->name,
                ],
                'year_level' => $section->yearLevel === null ? null : [
                    'id' => $section->yearLevel->id,
                    'level' => $section->yearLevel->level,
                    'name' => $section->yearLevel->name,
                ],
            ],
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'room' => $room === null ? null : [
                'id' => $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'building' => $room->building,
            ],
            'professors' => $schedule->scheduleAssignments
                ->map(fn ($assignment) => $assignment->facultyAssignment?->professor)
                ->filter(fn ($professor) => $professor?->user !== null)
                ->unique('id')
                ->values()
                ->map(fn ($professor): array => [
                    'id' => $professor->id,
                    'name' => $professor->user->name,
                ])
                ->all(),
        ];
    }

    private function matchesPairFilters(array $conflict, array $filters): bool
    {
        if (empty($filters['section_id']) && empty($filters['year_level_id'])) {
            return true;
        }

        return collect($conflict['classes'])->contains(fn (array $class): bool => (empty($filters['section_id']) || $class['section']['id'] === (int) $filters['section_id'])
            && (empty($filters['year_level_id'])
                || ($class['section']['year_level']['id'] ?? null) === (int) $filters['year_level_id']));
    }

    private function resolveTerm(?int $termId): ?AcademicTerm
    {
        return $termId === null
            ? $this->currentTerm()
            : AcademicTerm::query()->findOrFail($termId);
    }

    private function currentTerm(): ?AcademicTerm
    {
        return AcademicTerm::query()
            ->whereIn('status', ['active', 'planned'])
            ->whereDate('ends_on', '>=', CarbonImmutable::today())
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->first();
    }

    private function authorizedProgramIds(User $actor): ?array
    {
        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return null;
        }

        return $actor->departmentPersonnelPrograms()
            ->pluck('programs.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function assertProgramFilterAllowed(array $filters, ?array $allowedProgramIds): void
    {
        if ($allowedProgramIds === null) {
            return;
        }

        if (! empty($filters['program_id']) && ! in_array((int) $filters['program_id'], $allowedProgramIds, true)) {
            abort(403, 'You do not have access to this program.');
        }

        if (! empty($filters['section_id']) && ! Section::query()
            ->whereKey((int) $filters['section_id'])
            ->whereIn('program_id', $allowedProgramIds)
            ->exists()) {
            abort(403, 'You do not have access to this section.');
        }
    }

    private function termSummary(AcademicTerm $term): array
    {
        return [
            'id' => $term->id,
            'academic_year' => $term->academic_year,
            'term' => $term->term,
            'name' => $term->name,
            'starts_on' => $term->starts_on?->toDateString(),
            'ends_on' => $term->ends_on?->toDateString(),
        ];
    }
}
