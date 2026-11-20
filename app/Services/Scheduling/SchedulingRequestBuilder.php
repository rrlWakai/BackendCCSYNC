<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\FacultyAvailability;
use App\Models\Professor;
use App\Models\Room;
use App\Models\SubjectOffering;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SchedulingRequestBuilder
{
    private const ASSIGNMENT_ROLE_PRIORITY = [
        'lead' => 0,
        'co_instructor' => 1,
        'lab_instructor' => 2,
        'substitute' => 3,
    ];

    private const ASSIGNMENT_ROLES = ['lead', 'co_instructor', 'lab_instructor', 'substitute'];

    private const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'visiting'];

    private const ROOM_TYPES = ['lecture', 'laboratory', 'seminar', 'office', 'other'];

    private const WEEKDAYS = [1, 2, 3, 4, 5];

    private const AVAILABILITY_TYPES = ['available', 'preferred', 'unavailable'];

    public function __construct(
        private readonly SchedulingConfigurationProvider $configuration,
    ) {}

    public function build(
        AcademicTerm $term,
        ?string $correlationId = null,
        ?string $generationId = null,
    ): SchedulingRequestBuildResult {
        $issues = [];

        $offerings = $this->loadOfferings($term);

        if ($offerings->isEmpty()) {
            return SchedulingRequestBuildResult::failure([
                $this->issue(
                    'required_offerings_missing',
                    'No open class assignments are configured for this academic term.',
                    'academic_term',
                    (string) $term->id,
                ),
            ]);
        }

        $publishedSchedules = $this->loadPublishedSchedules($term);
        $draftScheduleIds = $this->loadDraftScheduleIds($term);
        $rooms = $this->loadRooms();

        $this->inspectTerm($term, $issues);
        $this->inspectAcademicStructure($offerings, $issues);

        $timeGrid = $this->configuration->timeGrid($term);
        $applicableDays = [];

        if ($timeGrid === null) {
            $issues[] = $this->issue(
                'time_grid_not_configured',
                'No approved term-scoped time grid is available.',
                'academic_term',
                (string) $term->id,
            );
        } else {
            $timeGrid = $this->normaliseTimeGrid($timeGrid, $term, $issues);
            $applicableDays = collect($timeGrid['days'] ?? [])
                ->pluck('day_of_week')
                ->map(fn ($day): int => (int) $day)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($applicableDays === []) {
                $issues[] = $this->issue(
                    'time_grid_invalid',
                    'The approved time grid does not define any applicable days.',
                    'academic_term',
                    (string) $term->id,
                );
            }
        }

        $meetingPlans = $this->configuration->meetingPlans($term, $offerings);
        $meetingPlanPayload = $this->mapMeetingPlans($offerings, $meetingPlans, $issues);

        $selection = $this->selectProfessorAssignments($offerings, $issues);
        $professors = $selection['professors'];
        $professorAssignments = $selection['assignments'];

        $professorPayload = $this->mapProfessors($term, $professors, $issues);
        $fullTimePolicy = $this->mapFullTimePolicy($term, $professors, $issues);
        $sectionAvailability = $this->configuration->sectionAvailabilityWindows(
            $term,
            $offerings->pluck('section')->filter()->unique('id')->values(),
        );
        $combinedClasses = $this->configuration->combinedClasses($term);
        $solverConfig = $this->solverConfig($issues);
        $baseline = $this->mapPublishedBaseline($publishedSchedules, $offerings, $issues);

        if ($issues !== []) {
            return SchedulingRequestBuildResult::failure($issues);
        }

        $payload = [
            'contract_version' => (string) config('scheduler.contract_version', '1.0'),
            'metadata' => [
                'generation_id' => $generationId ?? (string) Str::uuid(),
                'requested_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'academic_term_id' => (string) $term->id,
                'snapshot_version' => 1,
                'snapshot_sha256' => '',
                'correlation_id' => $correlationId ?? (string) Str::uuid(),
            ],
            'academic_term' => [
                'id' => (string) $term->id,
                'academic_year' => (string) $term->academic_year,
                'term' => (string) $term->term,
                'name' => (string) $term->name,
                'starts_on' => $term->starts_on?->toDateString() ?? '',
                'ends_on' => $term->ends_on?->toDateString() ?? '',
                'status' => (string) $term->status,
                'applicable_days' => $applicableDays,
            ],
            'time_grid' => $timeGrid,
            'generation_scope' => [
                'academic_term_id' => (string) $term->id,
                'included_offering_ids' => $offerings
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all(),
                'reoptimized_published_schedule_ids' => $publishedSchedules
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all(),
                'preserved_draft_schedule_ids' => $draftScheduleIds,
                'replacement_draft_schedule_ids' => [],
                'fixed_schedule_ids' => [],
            ],
            'published_timetable_baseline' => $baseline,
            'programs' => $this->mapPrograms($offerings),
            'year_levels' => $this->mapYearLevels($offerings),
            'sections' => $this->mapSections($offerings, $sectionAvailability, $issues),
            'subjects' => $this->mapSubjects($offerings),
            'offerings' => $this->mapOfferings($term, $offerings, $meetingPlanPayload),
            'professors' => $professorPayload,
            'professor_assignments' => $professorAssignments,
            'full_time_workday_policy' => $fullTimePolicy,
            'rooms' => $this->mapRooms($rooms),
            'combined_classes' => $combinedClasses,
            'existing_commitments' => [],
            'solver_config' => $solverConfig,
        ];

        $payload['metadata']['snapshot_sha256'] = SnapshotHasher::hash($payload);

        return SchedulingRequestBuildResult::success($payload);
    }

    private function loadOfferings(AcademicTerm $term): Collection
    {
        return SubjectOffering::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'open')
            ->with([
                'subject:id,code,title,status,units,lecture_hours,lab_hours',
                'section:id,code,name,program_id,year_level_id,academic_term_id,status,capacity',
                'section.program:id,code,name,status',
                'section.yearLevel:id,level,name',
                'facultyAssignments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('professor.user:id,name,status'),
            ])
            ->orderBy('id')
            ->get();
    }

    private function loadPublishedSchedules(AcademicTerm $term): Collection
    {
        return ClassSchedule::query()
            ->where('status', 'published')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->where('academic_term_id', $term->id))
            ->with([
                'scheduleAssignments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('facultyAssignment:id,professor_id,assignment_role,status'),
                'roomAllocations' => fn ($query) => $query->where('status', 'active'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function loadDraftScheduleIds(AcademicTerm $term): array
    {
        return ClassSchedule::query()
            ->where('status', 'draft')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->where('academic_term_id', $term->id))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function loadRooms(): Collection
    {
        return Room::query()
            ->where('status', 'available')
            ->orderBy('id')
            ->get(['id', 'capacity', 'room_type', 'status']);
    }

    private function inspectTerm(AcademicTerm $term, array &$issues): void
    {
        if (! in_array($term->status, ['active', 'planned'], true)) {
            $issues[] = $this->issue(
                'academic_term_not_schedulable',
                'The academic term must be active or planned.',
                'academic_term',
                (string) $term->id,
            );
        }

        if ($term->starts_on === null || $term->ends_on === null || $term->ends_on->lt($term->starts_on)) {
            $issues[] = $this->issue(
                'academic_term_dates_invalid',
                'The academic term must have a valid inclusive date range.',
                'academic_term',
                (string) $term->id,
            );
        }

        $academicYear = (string) $term->academic_year;

        if (preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches) !== 1
            || (int) $matches[2] !== (int) $matches[1] + 1) {
            $issues[] = $this->issue(
                'academic_year_invalid',
                'The academic year must use the YYYY-YYYY format with consecutive years.',
                'academic_term',
                (string) $term->id,
            );
        }

        if (! in_array($term->term, ['first', 'second', 'summer'], true)) {
            $issues[] = $this->issue(
                'academic_term_classification_invalid',
                'The academic term classification must be first, second, or summer.',
                'academic_term',
                (string) $term->id,
            );
        }
    }

    private function inspectAcademicStructure(Collection $offerings, array &$issues): void
    {
        foreach ($offerings as $offering) {
            $entityId = (string) $offering->id;

            if ($offering->section === null || (int) $offering->section->academic_term_id !== (int) $offering->academic_term_id) {
                $issues[] = $this->issue(
                    'offering_section_term_mismatch',
                    'The class assignment section does not belong to the selected academic term.',
                    'subject_offering',
                    $entityId,
                );

                continue;
            }

            if ($offering->section->status !== 'active') {
                $issues[] = $this->issue(
                    'offering_section_inactive',
                    'The class assignment section must be active.',
                    'subject_offering',
                    $entityId,
                );
            }

            if ($offering->subject === null || $offering->subject->status !== 'active') {
                $issues[] = $this->issue(
                    'offering_subject_inactive',
                    'The class assignment subject must be active.',
                    'subject_offering',
                    $entityId,
                );
            }

            if ($offering->section->program === null || $offering->section->program->status !== 'active') {
                $issues[] = $this->issue(
                    'offering_program_inactive',
                    'The class assignment program must be active.',
                    'subject_offering',
                    $entityId,
                );
            }

            if ($offering->section->yearLevel === null) {
                $issues[] = $this->issue(
                    'offering_year_level_missing',
                    'The class assignment section must have a year level.',
                    'subject_offering',
                    $entityId,
                );
            }

            if (! $this->isIdentifier($offering->section->code)) {
                $issues[] = $this->issue(
                    'section_code_invalid',
                    'The section code must be a non-empty identifier without whitespace.',
                    'section',
                    (string) $offering->section->id,
                );
            }

            if ($offering->subject !== null && ! $this->isIdentifier($offering->subject->code)) {
                $issues[] = $this->issue(
                    'subject_code_invalid',
                    'The subject code must be a non-empty identifier without whitespace.',
                    'subject',
                    (string) $offering->subject->id,
                );
            }

            if ($offering->section->program !== null && ! $this->isIdentifier($offering->section->program->code)) {
                $issues[] = $this->issue(
                    'program_code_invalid',
                    'The program code must be a non-empty identifier without whitespace.',
                    'program',
                    (string) $offering->section->program->id,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $timeGrid
     * @return array<string, mixed>
     */
    private function normaliseTimeGrid(array $timeGrid, AcademicTerm $term, array &$issues): array
    {
        $timeGrid['academic_term_id'] = (string) $term->id;
        $timeGrid['approval_status'] = 'approved';

        foreach (['configuration_id', 'approved_at', 'timezone'] as $field) {
            if (! is_string($timeGrid[$field] ?? null) || trim((string) $timeGrid[$field]) === '') {
                $issues[] = $this->issue(
                    'time_grid_invalid',
                    'The approved time grid is missing required configuration fields.',
                    'academic_term',
                    (string) $term->id,
                );

                break;
            }
        }

        $version = $this->nullableInt($timeGrid['version'] ?? null);
        if ($version === null || $version < 1) {
            $issues[] = $this->issue(
                'time_grid_invalid',
                'The approved time grid must have a positive version.',
                'academic_term',
                (string) $term->id,
            );
        } else {
            $timeGrid['version'] = $version;
        }

        $days = $timeGrid['days'] ?? null;

        if (! is_array($days) || $days === []) {
            $issues[] = $this->issue(
                'time_grid_invalid',
                'The approved time grid must define at least one day.',
                'academic_term',
                (string) $term->id,
            );

            $timeGrid['days'] = [];

            return $timeGrid;
        }

        $normalisedDays = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $normalisedDays[] = [
                'day_of_week' => (int) ($day['day_of_week'] ?? 0),
                'operating_start' => (string) ($day['operating_start'] ?? ''),
                'operating_end' => (string) ($day['operating_end'] ?? ''),
                'valid_start_times' => array_values(
                    is_array($day['valid_start_times'] ?? null) ? $day['valid_start_times'] : [],
                ),
            ];
        }

        $this->assertWeekdays(
            array_column($normalisedDays, 'day_of_week'),
            'time_grid.days',
            'time_grid_day_outside_weekdays',
            [(string) $term->id],
            $issues,
        );

        usort($normalisedDays, fn (array $left, array $right): int => $left['day_of_week'] <=> $right['day_of_week']);

        $timeGrid['days'] = $normalisedDays;

        return $timeGrid;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function mapMeetingPlans(Collection $offerings, array $plans, array &$issues): array
    {
        $payload = [];

        foreach ($offerings as $offering) {
            $plan = $plans[$offering->id] ?? $plans[(string) $offering->id] ?? null;

            if (! is_array($plan)) {
                $issues[] = $this->issue(
                    'meeting_plan_missing',
                    'No approved meeting plan is available for this class assignment.',
                    'subject_offering',
                    (string) $offering->id,
                );

                continue;
            }

            $planId = $plan['meeting_plan_id'] ?? null;
            $planVersion = $this->nullableInt($plan['meeting_plan_version'] ?? null);
            $approvedAt = $plan['meeting_plan_approved_at'] ?? null;
            $requirements = $plan['meeting_requirements'] ?? null;

            if (! is_string($planId) || trim($planId) === ''
                || $planVersion === null || $planVersion < 1
                || ! is_string($approvedAt) || trim($approvedAt) === ''
                || ! is_array($requirements) || $requirements === []) {
                $issues[] = $this->issue(
                    'meeting_plan_invalid',
                    'The approved meeting plan is missing required fields or meeting requirements.',
                    'subject_offering',
                    (string) $offering->id,
                );

                continue;
            }

            foreach (array_values($requirements) as $index => $requirement) {
                if (! is_array($requirement)) {
                    continue;
                }

                $allowedDays = $requirement['allowed_days'] ?? null;
                if ($allowedDays !== null) {
                    $this->assertWeekdays(
                        $allowedDays,
                        'meeting_requirements.allowed_days',
                        'meeting_allowed_day_outside_weekdays',
                        [(string) $offering->id, (string) $index],
                        $issues,
                    );
                }

                $preferredWindow = $requirement['preferred_window'] ?? null;
                if (is_array($preferredWindow) && array_key_exists('day_of_week', $preferredWindow)) {
                    $this->assertWeekdays(
                        [$preferredWindow['day_of_week']],
                        'meeting_requirements.preferred_window.day_of_week',
                        'meeting_preferred_day_outside_weekdays',
                        [(string) $offering->id, (string) $index],
                        $issues,
                    );
                }
            }

            $payload[$offering->id] = [
                'meeting_plan_id' => $planId,
                'meeting_plan_version' => $planVersion,
                'meeting_plan_approved_at' => $approvedAt,
                'meeting_requirements' => array_values($requirements),
            ];
        }

        return $payload;
    }

    /**
     * @return array{professors: Collection<int, Professor>, assignments: list<array<string, mixed>>}
     */
    private function selectProfessorAssignments(Collection $offerings, array &$issues): array
    {
        $professors = collect();
        $assignments = [];

        foreach ($offerings as $offering) {
            $eligible = $offering->facultyAssignments
                ->filter(fn ($assignment): bool => $assignment->professor !== null
                    && $assignment->professor->status === 'active'
                    && $assignment->professor->user?->status === 'active'
                    && in_array($assignment->assignment_role, self::ASSIGNMENT_ROLES, true));

            if ($eligible->isEmpty()) {
                $issues[] = $this->issue(
                    'professor_assignment_missing',
                    'The class assignment has no eligible active professor assignment.',
                    'subject_offering',
                    (string) $offering->id,
                );

                continue;
            }

            $distinctProfessorIds = $eligible
                ->pluck('professor_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($distinctProfessorIds->count() > 1) {
                $issues[] = $this->issue(
                    'multiple_distinct_professors',
                    'The class assignment has multiple distinct active professors. Exactly one distinct professor is required.',
                    'subject_offering',
                    (string) $offering->id,
                    $distinctProfessorIds->map(fn ($id): string => (string) $id)->all(),
                );

                continue;
            }

            $canonical = $eligible
                ->sortBy(fn ($assignment): string => sprintf(
                    '%02d-%012d',
                    self::ASSIGNMENT_ROLE_PRIORITY[$assignment->assignment_role] ?? 99,
                    (int) $assignment->id,
                ))
                ->first();

            $professor = $canonical->professor;

            if (! in_array($professor->employment_type, self::EMPLOYMENT_TYPES, true)) {
                $issues[] = $this->issue(
                    'professor_employment_type_invalid',
                    'The professor employment type is not supported by the scheduling contract.',
                    'professor',
                    (string) $professor->id,
                );

                continue;
            }

            $assignments[] = [
                'id' => (string) $canonical->id,
                'professor_id' => (string) $professor->id,
                'subject_offering_id' => (string) $offering->id,
                'assignment_role' => (string) $canonical->assignment_role,
                'status' => 'active',
                'eligible_for_scheduling' => true,
                'assigned_units' => $this->decimal($canonical->assigned_units),
            ];

            $professors->put($professor->id, $professor);
        }

        return [
            'professors' => $professors,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  Collection<int, Professor>  $professors
     * @return list<array<string, mixed>>
     */
    private function mapProfessors(AcademicTerm $term, Collection $professors, array &$issues): array
    {
        if ($professors->isEmpty()) {
            return [];
        }

        $windows = $this->loadAvailability($term, $professors->keys()->all());
        $payload = [];

        foreach ($professors as $professor) {
            $target = null;

            if ($professor->employment_type === 'part_time') {
                $target = $this->configuration->partTimeWeeklyTargetMinutes($professor);

                if ($target === null || $target < 0) {
                    $issues[] = $this->issue(
                        'part_time_weekly_target_missing',
                        'Part-time professors require an explicit weekly teaching-minute target.',
                        'professor',
                        (string) $professor->id,
                    );
                }
            }

            $payload[] = [
                'id' => (string) $professor->id,
                'employment_type' => (string) $professor->employment_type,
                'profile_status' => 'active',
                'account_status' => 'active',
                'availability_windows' => $windows[$professor->id] ?? [],
                'part_time_weekly_target_minutes' => $target,
            ];
        }

        return $payload;
    }

    /**
     * @param  list<int>  $professorIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadAvailability(AcademicTerm $term, array $professorIds): array
    {
        return FacultyAvailability::query()
            ->where('academic_term_id', $term->id)
            ->whereIn('professor_id', $professorIds)
            ->orderBy('professor_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy('professor_id')
            ->map(fn (Collection $rows): array => $rows
                ->map(fn ($row): array => [
                    'day_of_week' => (int) $row->day_of_week,
                    'start_time' => substr((string) $row->start_time, 0, 5),
                    'end_time' => substr((string) $row->end_time, 0, 5),
                    'availability_type' => (string) $row->availability_type,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  Collection<int, Professor>  $professors
     * @return array<string, mixed>|null
     */
    private function mapFullTimePolicy(AcademicTerm $term, Collection $professors, array &$issues): ?array
    {
        $fullTimeProfessors = $professors
            ->filter(fn (Professor $professor): bool => $professor->employment_type === 'full_time');

        if ($fullTimeProfessors->isEmpty()) {
            return null;
        }

        $policy = $this->configuration->fullTimeWorkdayPolicy($term, $professors->values());

        if ($policy === null) {
            $issues[] = $this->issue(
                'full_time_workday_policy_not_configured',
                'Full-time professors require an approved term-scoped workday policy and authorized exceptions.',
                'academic_term',
                (string) $term->id,
                $fullTimeProfessors->keys()->map(fn ($id): string => (string) $id)->values()->all(),
            );

            return null;
        }

        $policy['academic_term_id'] = (string) $term->id;
        $valid = true;
        $standardDays = $policy['standard_days'] ?? null;

        $this->assertWeekdays(
            $standardDays,
            'full_time_workday_policy.standard_days',
            'full_time_policy_day_outside_weekdays',
            [(string) $term->id],
            $issues,
        );

        if (! is_array($standardDays) || $standardDays === [] || ! array_is_list($standardDays)) {
            $valid = false;
            $issues[] = $this->issue(
                'full_time_workday_policy_invalid',
                'The approved full-time workday policy must define standard days as a list.',
                'academic_term',
                (string) $term->id,
            );
        } else {
            $sortedDays = array_values(array_unique(array_map(fn ($day): int => (int) $day, $standardDays)));
            sort($sortedDays);

            if ($sortedDays !== $standardDays) {
                $valid = false;
                $issues[] = $this->issue(
                    'full_time_workday_policy_invalid',
                    'Standard days must be unique and sorted.',
                    'academic_term',
                    (string) $term->id,
                );
            }

            $policy['standard_days'] = $sortedDays;
        }

        $exceptions = $policy['exceptions'] ?? [];

        if (! is_array($exceptions) || ! array_is_list($exceptions)) {
            $valid = false;
            $issues[] = $this->issue(
                'full_time_workday_policy_invalid',
                'Full-time workday policy exceptions must be a list.',
                'academic_term',
                (string) $term->id,
            );

            return null;
        }

        foreach ($exceptions as $index => $exception) {
            if (! is_array($exception)) {
                $valid = false;
                $issues[] = $this->issue(
                    'full_time_workday_exception_invalid',
                    'Each full-time workday exception must be an object.',
                    'academic_term',
                    (string) $term->id,
                    [(string) $index],
                );

                continue;
            }

            foreach (array_keys($exception) as $field) {
                if (! in_array($field, ['professor_id', 'authorized_additional_days'], true)) {
                    $valid = false;
                    $issues[] = $this->issue(
                        'unsupported_full_time_workday_exception_field',
                        'Unsupported full-time workday exception field.',
                        'academic_term',
                        (string) $term->id,
                        [(string) $index],
                    );
                }
            }

            foreach (['professor_id', 'authorized_additional_days'] as $field) {
                if (! array_key_exists($field, $exception)) {
                    $valid = false;
                    $issues[] = $this->issue(
                        'missing_full_time_workday_exception_field',
                        'Full-time workday exception field is required.',
                        'academic_term',
                        (string) $term->id,
                        [(string) $index],
                    );
                }
            }

            $professorId = $exception['professor_id'] ?? null;

            if (! is_int($professorId) && ! (is_string($professorId) && $professorId !== '')) {
                $valid = false;
                $issues[] = $this->issue(
                    'full_time_workday_exception_invalid',
                    'Full-time workday exception professor_id is required.',
                    'academic_term',
                    (string) $term->id,
                    [(string) $index],
                );
            }

            $days = $exception['authorized_additional_days'] ?? null;

            $this->assertWeekdays(
                $days,
                'full_time_workday_policy.exceptions.authorized_additional_days',
                'full_time_policy_day_outside_weekdays',
                [(string) $term->id],
                $issues,
            );

            if (! is_array($days) || $days === [] || ! array_is_list($days)) {
                $valid = false;
                $issues[] = $this->issue(
                    'full_time_workday_exception_invalid',
                    'Authorized additional days must be a nonempty list.',
                    'academic_term',
                    (string) $term->id,
                    [(string) $index],
                );

                continue;
            }

            $sortedDays = array_values(array_unique(array_map(fn ($day): int => (int) $day, $days)));
            sort($sortedDays);

            if ($sortedDays !== $days) {
                $valid = false;
                $issues[] = $this->issue(
                    'full_time_workday_exception_invalid',
                    'Authorized additional days must be unique and sorted.',
                    'academic_term',
                    (string) $term->id,
                    [(string) $index],
                );
            }

            $exceptions[$index]['authorized_additional_days'] = $sortedDays;
        }

        $policy['exceptions'] = array_values($exceptions);

        return $valid ? $policy : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function solverConfig(array &$issues): ?array
    {
        $config = config('scheduler.solver');

        if (! is_array($config)) {
            $issues[] = $this->issue(
                'solver_config_not_configured',
                'The solver configuration is missing.',
            );

            return null;
        }

        $timeLimit = $this->nullableInt($config['time_limit_ms'] ?? null);
        $workerCount = $this->nullableInt($config['worker_count'] ?? null);
        $randomSeed = $this->nullableInt($config['random_seed'] ?? null);
        $weights = is_array($config['objective_weights'] ?? null) ? $config['objective_weights'] : null;

        $valid = true;

        if ($timeLimit === null || $timeLimit < 1 || $timeLimit > 300000) {
            $issues[] = $this->issue(
                'solver_time_limit_invalid',
                'The solver time limit must be an integer between 1 and 300000 milliseconds.',
            );

            $valid = false;
        }

        if ($workerCount === null || $workerCount < 1) {
            $issues[] = $this->issue(
                'solver_worker_count_invalid',
                'The solver worker count must be a positive integer.',
            );

            $valid = false;
        }

        if ($randomSeed === null || $randomSeed < 0 || $randomSeed > 2147483647) {
            $issues[] = $this->issue(
                'solver_random_seed_invalid',
                'The solver random seed must be an integer between 0 and 2147483647.',
            );

            $valid = false;
        }

        $weightKeys = [
            'part_time_target_deviation_per_minute',
            'full_time_load_spread_per_minute',
            'professor_gap_per_minute',
            'section_gap_per_minute',
            'room_capacity_waste_per_seat',
            'section_daily_load_spread_per_minute',
            'preferred_window_miss_per_meeting',
            'optional_meeting_omission',
            'published_placement_change_per_meeting',
        ];

        $normalisedWeights = [];

        foreach ($weightKeys as $key) {
            $value = $this->nullableInt($weights[$key] ?? null);

            if ($value === null || $value < 0 || ($key === 'published_placement_change_per_meeting' && $value < 1)) {
                $issues[] = $this->issue(
                    'solver_objective_weight_invalid',
                    "The solver objective weight '{$key}' is missing or invalid.",
                );

                $valid = false;

                continue;
            }

            $normalisedWeights[$key] = $value;
        }

        if (! $valid) {
            return null;
        }

        return [
            'time_limit_ms' => $timeLimit,
            'worker_count' => $workerCount,
            'random_seed' => $randomSeed,
            'objective_weights' => $normalisedWeights,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapPublishedBaseline(Collection $publishedSchedules, Collection $offerings, array &$issues): array
    {
        $offeringsById = $offerings->keyBy('id');
        $payload = [];

        foreach ($publishedSchedules as $schedule) {
            $offering = $offeringsById->get($schedule->subject_offering_id);

            if ($offering === null) {
                $issues[] = $this->issue(
                    'published_schedule_outside_required_set',
                    'A published schedule belongs to a class assignment outside the authoritative required set.',
                    'class_schedule',
                    (string) $schedule->id,
                );

                continue;
            }

            $activeAssignments = $schedule->scheduleAssignments
                ->filter(fn ($assignment): bool => $assignment->facultyAssignment !== null
                    && $assignment->facultyAssignment->status === 'active');

            $professorIds = $activeAssignments
                ->pluck('facultyAssignment.professor_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $professorIdsPayload = [];

            if ($professorIds->count() === 1) {
                $professorIdsPayload = [(string) $professorIds->first()];
            } elseif ($professorIds->count() > 1) {
                $canonical = $activeAssignments
                    ->sortBy(fn ($assignment): string => sprintf(
                        '%02d-%012d',
                        self::ASSIGNMENT_ROLE_PRIORITY[$assignment->facultyAssignment->assignment_role] ?? 99,
                        (int) $assignment->facultyAssignment->id,
                    ))
                    ->first();

                $professorIdsPayload = [(string) $canonical->facultyAssignment->professor_id];
            }

            $allocations = $schedule->roomAllocations
                ->filter(fn ($allocation): bool => $allocation->status === 'active')
                ->map(fn ($allocation): array => [
                    'allocation_id' => (string) $allocation->id,
                    'room_id' => (string) $allocation->room_id,
                ])
                ->values()
                ->all();

            if ($schedule->modality === 'online' && $allocations !== []) {
                $issues[] = $this->issue(
                    'published_schedule_online_room',
                    'An online published schedule has an active physical room allocation.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }

            if ($schedule->modality !== 'online' && count($allocations) !== 1) {
                $issues[] = $this->issue(
                    'published_schedule_room_allocation_mismatch',
                    'A physical published schedule must have exactly one active room allocation.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }

            $payload[] = [
                'schedule_id' => (string) $schedule->id,
                'subject_offering_id' => (string) $schedule->subject_offering_id,
                'meeting_instance_id' => $schedule->meeting_instance_id === null
                    ? null
                    : (string) $schedule->meeting_instance_id,
                'day_of_week' => (int) $schedule->day_of_week,
                'start_time' => substr((string) $schedule->start_time, 0, 5),
                'end_time' => substr((string) $schedule->end_time, 0, 5),
                'section_ids' => [(string) $offering->section_id],
                'professor_ids' => $professorIdsPayload,
                'room_allocations' => $allocations,
            ];
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapPrograms(Collection $offerings): array
    {
        return $offerings
            ->map(fn (SubjectOffering $offering) => $offering->section?->program)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($program): array => [
                'id' => (string) $program->id,
                'code' => (string) $program->code,
                'name' => (string) $program->name,
                'status' => 'active',
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapYearLevels(Collection $offerings): array
    {
        return $offerings
            ->map(fn (SubjectOffering $offering) => $offering->section?->yearLevel)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($yearLevel): array => [
                'id' => (string) $yearLevel->id,
                'level' => (int) $yearLevel->level,
                'name' => (string) $yearLevel->name,
            ])
            ->all();
    }

    /**
     * @param  array<int|string, list<array<string, mixed>>>  $availability
     * @return list<array<string, mixed>>
     */
    private function mapSections(Collection $offerings, array $availability, array &$issues): array
    {
        $sections = $offerings
            ->map(fn (SubjectOffering $offering) => $offering->section)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values();

        $mapped = [];
        foreach ($sections as $section) {
            $windows = array_values(
                $availability[$section->id] ?? $availability[(string) $section->id] ?? [],
            );
            foreach ($windows as $window) {
                if (is_array($window)) {
                    $this->assertAvailabilityWindow($window, [(string) $section->id], $issues);
                } else {
                    $issues[] = $this->issue('availability_window_object_required', 'Section availability window must be an object.', 'section', (string) $section->id, [(string) $section->id]);
                }
            }

            $mapped[] = [
                'id' => (string) $section->id,
                'program_id' => (string) $section->program_id,
                'year_level_id' => (string) $section->year_level_id,
                'academic_term_id' => (string) $section->academic_term_id,
                'code' => (string) $section->code,
                'name' => (string) ($section->name ?: $section->code),
                'capacity' => $section->capacity,
                'status' => 'active',
                'availability_windows' => $windows,
            ];
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapSubjects(Collection $offerings): array
    {
        return $offerings
            ->map(fn (SubjectOffering $offering) => $offering->subject)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($subject): array => [
                'id' => (string) $subject->id,
                'code' => (string) $subject->code,
                'title' => (string) $subject->title,
                'status' => 'active',
                'units' => $this->decimal($subject->units),
                'lecture_hours' => $this->decimal($subject->lecture_hours),
                'lab_hours' => $this->decimal($subject->lab_hours),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $meetingPlans
     * @return list<array<string, mixed>>
     */
    private function mapOfferings(AcademicTerm $term, Collection $offerings, array $meetingPlans): array
    {
        return $offerings
            ->map(function (SubjectOffering $offering) use ($term, $meetingPlans): array {
                $plan = $meetingPlans[$offering->id];

                return [
                    'id' => (string) $offering->id,
                    'academic_term_id' => (string) $term->id,
                    'section_id' => (string) $offering->section_id,
                    'subject_id' => (string) $offering->subject_id,
                    'expected_enrollment' => $offering->expected_students,
                    'status' => 'open',
                    'meeting_plan_id' => $plan['meeting_plan_id'],
                    'meeting_plan_version' => $plan['meeting_plan_version'],
                    'meeting_plan_approval_status' => 'approved',
                    'meeting_plan_approved_at' => $plan['meeting_plan_approved_at'],
                    'meeting_requirements' => $plan['meeting_requirements'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapRooms(Collection $rooms): array
    {
        return $rooms
            ->filter(fn ($room): bool => (int) $room->capacity >= 1
                && in_array($room->room_type, self::ROOM_TYPES, true))
            ->map(fn ($room): array => [
                'id' => (string) $room->id,
                'capacity' => (int) $room->capacity,
                'room_type' => (string) $room->room_type,
                'status' => 'available',
            ])
            ->values()
            ->all();
    }

    private function isClock(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private function clockMinutes(mixed $value): ?int
    {
        if (! $this->isClock($value)) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return $hours * 60 + $minutes;
    }

    /**
     * @param  list<string>  $relatedIds
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function assertWeekdays(mixed $days, string $path, string $code, array $relatedIds, array &$issues): void
    {
        if (! is_array($days) || $days === []) {
            $issues[] = $this->issue($code, 'At least one Monday-Friday day is required.', 'time_grid', null, $relatedIds);

            return;
        }

        foreach (array_values($days) as $day) {
            if (! is_int($day) || ! in_array($day, self::WEEKDAYS, true)) {
                $issues[] = $this->issue($code, 'Only weekdays 1 through 5 are allowed in contract 1.0.', 'time_grid', null, $relatedIds);

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $window
     * @param  list<string>  $relatedIds
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function assertAvailabilityWindow(array $window, array $relatedIds, array &$issues): void
    {
        $allowedKeys = ['day_of_week', 'start_time', 'end_time', 'availability_type'];
        foreach (array_keys($window) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $issues[] = $this->issue('unsupported_availability_window_field', 'Unsupported availability window field.', 'section', null, $relatedIds);
            }
        }

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $window)) {
                $issues[] = $this->issue('missing_availability_window_field', 'Availability window field is required.', 'section', null, $relatedIds);
            }
        }

        $day = $window['day_of_week'] ?? null;
        if (! is_int($day) || ! in_array($day, self::WEEKDAYS, true)) {
            $issues[] = $this->issue('availability_day_outside_weekdays', 'Section availability day must be Monday through Friday.', 'section', null, $relatedIds);
        }

        if (! $this->isClock($window['start_time'] ?? null) || ! $this->isClock($window['end_time'] ?? null)) {
            $issues[] = $this->issue('availability_window_invalid_clock', 'Availability window times must use HH:mm.', 'section', null, $relatedIds);
        }

        $type = $window['availability_type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::AVAILABILITY_TYPES, true)) {
            $issues[] = $this->issue('availability_window_invalid_type', 'Availability type must be available, preferred, or unavailable.', 'section', null, $relatedIds);
        }

        $start = $this->clockMinutes($window['start_time'] ?? null);
        $end = $this->clockMinutes($window['end_time'] ?? null);
        if ($start !== null && $end !== null && $start >= $end) {
            $issues[] = $this->issue('availability_window_invalid_range', 'Availability window end must follow start.', 'section', null, $relatedIds);
        }
    }

    private function isIdentifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match('/\s/', $value) !== 1;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $relatedIds
     * @return array<string, mixed>
     */
    private function issue(
        string $code,
        string $message,
        ?string $entityType = null,
        ?string $entityId = null,
        array $relatedIds = [],
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'related_ids' => array_values($relatedIds),
        ];
    }
}
