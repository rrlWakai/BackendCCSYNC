<?php

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\CurriculumRequirement;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\ScheduleConflict;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use App\Support\SchedulerResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Throwable;

final class SchedulingReadinessService
{
    private const LIFECYCLE_ISSUE_CODES = [
        'generation_records_not_configured',
        'snapshot_history_not_configured',
        'candidate_persistence_not_configured',
        'candidate_revision_history_not_configured',
        'published_baseline_unlinked',
    ];

    private const CAPABILITY_LIMITATION_ISSUE_CODES = [
        'section_availability_not_configured',
        'combined_classes_not_configured',
        'fixed_schedule_protection_not_supported',
        'replacement_draft_selection_not_supported',
        'existing_commitments_not_supported',
    ];

    private const INTEGRATION_ISSUE_CODES = [
        'scheduler_service_not_configured',
        'scheduler_api_key_not_configured',
        'scheduler_contract_version_unsupported',
        'scheduler_liveness_check_failed',
        'scheduler_service_not_ready',
        'scheduler_service_authentication_failed',
    ];

    private const ASSIGNMENT_ROLES = ['lead', 'co_instructor', 'lab_instructor', 'substitute'];

    private const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'visiting'];

    private const ROOM_TYPES = ['lecture', 'laboratory', 'seminar', 'office', 'other'];

    public function __construct(
        private readonly SchedulingConfigurationProvider $configuration,
        private readonly SchedulerClient $scheduler,
    ) {}

    public function inspect(AcademicTerm $term): array
    {
        $offerings = SubjectOffering::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'open')
            ->with([
                'subject:id,code,title,status,units,lecture_hours,lab_hours',
                'section:id,code,name,program_id,year_level_id,major_id,academic_term_id,status,capacity',
                'section.program:id,code,name,status',
                'section.yearLevel:id,level,name',
                'facultyAssignments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('professor.user:id,name,status'),
            ])
            ->orderBy('id')
            ->get();

        $offeringIds = $offerings
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $publishedSchedules = ClassSchedule::query()
            ->where('status', 'published')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->where('academic_term_id', $term->id))
            ->with([
                'subjectOffering:id,academic_term_id,section_id,subject_id',
                'scheduleAssignments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('facultyAssignment:id,professor_id,status'),
                'roomAllocations' => fn ($query) => $query->where('status', 'active'),
            ])
            ->orderBy('id')
            ->get();

        $draftCount = ClassSchedule::query()
            ->where('status', 'draft')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->where('academic_term_id', $term->id))
            ->count();

        $rooms = Room::query()
            ->where('status', 'available')
            ->orderBy('id')
            ->get(['id', 'code', 'capacity', 'room_type', 'status']);

        $openConflictCount = ScheduleConflict::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'open')
            ->count();

        $issues = [];
        $timeGrid = $this->configuration->timeGrid($term);
        $meetingPlans = $this->configuration->meetingPlans($term, $offerings);

        $this->inspectIntegration($issues);
        $schedulerService = $this->inspectSchedulerService($issues);
        $this->inspectTerm($term, $issues);
        $this->inspectOfferings($term, $offerings, $issues);
        $this->inspectCurriculum($term, $offerings, $issues);
        $assignedProfessors = $this->inspectAssignments($offerings, $issues);
        $fullTimePolicy = $this->configuration->fullTimeWorkdayPolicy($term, $assignedProfessors->values());
        $capabilityDetails = $this->configuration->capabilityStatus();
        $capabilities = $this->capabilities(
            $timeGrid,
            $meetingPlans,
            $offerings,
            $assignedProfessors,
            $fullTimePolicy,
            $capabilityDetails,
        );

        $this->inspectAvailability($term, $assignedProfessors, $issues);
        $this->inspectRooms($rooms, $offerings, $issues);
        $this->inspectPublishedSchedules($publishedSchedules, $offeringIds, $issues);
        $this->inspectDraftsAndConflicts($draftCount, $openConflictCount, $issues);
        $this->inspectCapabilities(
            $term,
            $offerings,
            $assignedProfessors,
            $timeGrid,
            $meetingPlans,
            $capabilities,
            $capabilityDetails,
            $issues,
        );

        $issues = array_map(function (array $issue): array {
            $issue['layer'] = match (true) {
                in_array($issue['code'], self::INTEGRATION_ISSUE_CODES, true) => 'integration',
                in_array($issue['code'], self::LIFECYCLE_ISSUE_CODES, true) => 'lifecycle',
                in_array($issue['code'], self::CAPABILITY_LIMITATION_ISSUE_CODES, true) => 'generation_data',
                default => 'generation_data',
            };

            return $issue;
        }, $issues);

        $layers = [
            'integration' => $this->layerSummary($issues, 'integration'),
            'generation_data' => $this->layerSummary($issues, 'generation_data'),
            'lifecycle' => $this->layerSummary($issues, 'lifecycle'),
        ];

        $apiKeyConfigured = is_string(config('scheduler.api_key'))
            && trim((string) config('scheduler.api_key')) !== '';
        $pythonServiceAvailable = ($schedulerService['live']['ok'] ?? false) === true;
        $authenticationReady = $apiKeyConfigured
            && ($schedulerService['ready']['ok'] ?? false) === true
            && ($schedulerService['ready']['error_code'] ?? null) !== 'scheduler_authentication_failed';
        $contractCompatible = config('scheduler.contract_version') === '1.0';
        $candidatePersistenceReady = ($capabilities['candidate_persistence'] ?? false) === true
            && ($capabilities['candidate_revisions'] ?? false) === true;

        $blockingCount = $this->countSeverity($issues, 'blocking');
        $warningCount = $this->countSeverity($issues, 'warning');
        $infoCount = $this->countSeverity($issues, 'info');

        return [
            'academic_term' => [
                'id' => (string) $term->id,
                'academic_year' => $term->academic_year,
                'term' => $term->term,
                'name' => $term->name,
                'status' => $term->status,
                'starts_on' => $term->starts_on?->toDateString(),
                'ends_on' => $term->ends_on?->toDateString(),
            ],
            'ready' => $blockingCount === 0,
            'integration_ready' => $layers['integration']['ready'],
            'generation_data_ready' => $layers['generation_data']['ready'],
            'lifecycle_ready' => $layers['lifecycle']['ready'],
            'generation_available' => $layers['integration']['ready']
                && $layers['generation_data']['ready']
                && $layers['lifecycle']['ready']
                && $candidatePersistenceReady
                && $contractCompatible,
            'python_service_available' => $pythonServiceAvailable,
            'authentication_ready' => $authenticationReady,
            'contract_compatible' => $contractCompatible,
            'candidate_persistence_ready' => $candidatePersistenceReady,
            'blocking_count' => $blockingCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'counts' => [
                'offerings' => $offerings->count(),
                'programs' => $offerings
                    ->pluck('section.program_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'year_levels' => $offerings
                    ->pluck('section.year_level_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'sections' => $offerings
                    ->pluck('section_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'assigned_professors' => $assignedProfessors->count(),
                'available_rooms' => $rooms->count(),
                'published_schedules' => $publishedSchedules->count(),
                'draft_schedules' => $draftCount,
                'open_conflicts' => $openConflictCount,
            ],
            'issues' => $issues,
            'layers' => $layers,
            'capabilities' => $capabilities,
            'capability_details' => $capabilityDetails,
            'scheduler_service' => $schedulerService,
            'inspected_at' => now()->toIso8601String(),
        ];
    }

    private function inspectIntegration(array &$issues): void
    {
        $url = config('scheduler.url');

        if (! is_string($url) || trim($url) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addIssue(
                $issues,
                'blocking',
                'scheduler_service_not_configured',
                'integration',
                'SCHEDULER_URL is not configured with a valid URL. The scheduling engine cannot be reached.',
            );
        }

        $apiKey = config('scheduler.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->addIssue(
                $issues,
                'blocking',
                'scheduler_api_key_not_configured',
                'integration',
                'SCHEDULER_API_KEY is not configured. The scheduling engine will reject requests.',
            );
        }

        if (config('scheduler.contract_version') !== '1.0') {
            $this->addIssue(
                $issues,
                'blocking',
                'scheduler_contract_version_unsupported',
                'integration',
                'The configured scheduler contract version is not supported by this backend.',
            );
        }
    }

    private function inspectSchedulerService(array &$issues): array
    {
        $checkedAt = now()->toIso8601String();
        $url = config('scheduler.url');
        $configured = is_string($url) && trim($url) !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
        $apiKeyConfigured = is_string(config('scheduler.api_key')) && trim((string) config('scheduler.api_key')) !== '';

        if (! $configured) {
            return [
                'configured' => false,
                'live' => ['ok' => false, 'checked_at' => $checkedAt],
                'ready' => ['ok' => false, 'checked_at' => $checkedAt],
                'checked_at' => $checkedAt,
            ];
        }

        $ttl = (int) config('scheduler.health_cache_seconds', 30);
        $cachePrefix = 'scheduling.scheduler.'.sha1(trim((string) $url)).'.';
        $live = Cache::remember(
            $cachePrefix.'live',
            $ttl,
            fn (): array => $this->schedulerResponseSnapshot($this->scheduler->live()),
        );
        $ready = $apiKeyConfigured
            ? Cache::remember(
                $cachePrefix.'ready',
                $ttl,
                fn (): array => $this->schedulerResponseSnapshot($this->scheduler->ready()),
            )
            : [
                'ok' => false,
                'message' => 'SCHEDULER_API_KEY is not configured.',
                'error_code' => 'scheduler_api_key_not_configured',
                'body' => null,
            ];

        if ($live['ok'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'scheduler_liveness_check_failed',
                'integration',
                'The scheduling engine did not pass its public liveness check.',
            );
        }

        if ($ready['ok'] !== true && $apiKeyConfigured) {
            $authenticationFailed = ($ready['error_code'] ?? null) === 'scheduler_authentication_failed';

            $this->addIssue(
                $issues,
                'blocking',
                $authenticationFailed
                    ? 'scheduler_service_authentication_failed'
                    : 'scheduler_service_not_ready',
                'integration',
                $authenticationFailed
                    ? 'The scheduling engine rejected the configured service credentials.'
                    : 'The scheduling engine did not pass its authenticated readiness check.',
            );
        }

        $live['checked_at'] = $checkedAt;
        $ready['checked_at'] = $checkedAt;

        return [
            'configured' => true,
            'live' => $live,
            'ready' => $ready,
            'checked_at' => $checkedAt,
        ];
    }

    private function schedulerResponseSnapshot(SchedulerResponse $response): array
    {
        return [
            'ok' => $response->isOk(),
            'message' => $response->getMessage(),
            'error_code' => $response->getErrorCode(),
            'body' => $response->getBody(),
        ];
    }

    private function inspectTerm(AcademicTerm $term, array &$issues): void
    {
        if (! in_array($term->status, ['active', 'planned'], true)) {
            $this->addIssue(
                $issues,
                'blocking',
                'academic_term_not_schedulable',
                'academic',
                'The academic term must be active or planned before generation.',
                'academic_term',
                (string) $term->id,
            );
        }

        if ($term->starts_on === null || $term->ends_on === null || $term->ends_on->lt($term->starts_on)) {
            $this->addIssue(
                $issues,
                'blocking',
                'academic_term_dates_invalid',
                'academic',
                'The academic term must have a valid inclusive date range.',
                'academic_term',
                (string) $term->id,
            );
        }
    }

    private function inspectOfferings(AcademicTerm $term, Collection $offerings, array &$issues): void
    {
        if ($offerings->isEmpty()) {
            $this->addIssue(
                $issues,
                'blocking',
                'required_offerings_missing',
                'academic',
                'No open class assignments are configured for this academic term.',
                'academic_term',
                (string) $term->id,
            );

            return;
        }

        $this->addIssue(
            $issues,
            'info',
            'authoritative_offering_set',
            'academic',
            'The required class assignment set is every open class assignment for the term. Curriculum mappings are used to validate each class assignment and to report missing class assignments; they never create class assignments automatically.',
            'academic_term',
            (string) $term->id,
            $offerings->pluck('id')->map(fn ($id): string => (string) $id)->all(),
        );

        foreach ($offerings as $offering) {
            $context = 'subject_offering';
            $entityId = (string) $offering->id;

            if ($offering->section === null || $offering->section->academic_term_id !== $term->id) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'offering_section_term_mismatch',
                    'academic',
                    'The class assignment section does not belong to the selected academic term.',
                    $context,
                    $entityId,
                );

                continue;
            }

            if ($offering->section->status !== 'active') {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'offering_section_inactive',
                    'academic',
                    'The class assignment section must be active.',
                    $context,
                    $entityId,
                );
            }

            if ($offering->subject === null || $offering->subject->status !== 'active') {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'offering_subject_inactive',
                    'academic',
                    'The class assignment subject must be active.',
                    $context,
                    $entityId,
                );
            }

            if ($offering->section->program === null || $offering->section->program->status !== 'active') {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'offering_program_inactive',
                    'academic',
                    'The class assignment program must be active.',
                    $context,
                    $entityId,
                );
            }

            if ($offering->expected_students === null) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'expected_enrollment_missing',
                    'academic',
                    'Expected enrollment is missing. It is required before any physical-room meeting can be generated.',
                    $context,
                    $entityId,
                );
            }
        }
    }

    private function inspectCurriculum(AcademicTerm $term, Collection $offerings, array &$issues): void
    {
        if ($offerings->isEmpty()) {
            return;
        }

        $semester = $term->term;

        if (! in_array($semester, CurriculumRequirement::SEMESTERS, true)) {
            $this->addIssue(
                $issues,
                'warning',
                'curriculum_semester_unrecognized',
                'academic',
                'The academic term classification is not a recognized semester, so curriculum mappings cannot be matched.',
                'academic_term',
                (string) $term->id,
            );

            return;
        }

        $sections = Section::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'program_id', 'year_level_id', 'major_id']);

        if ($sections->isEmpty()) {
            return;
        }

        $requirements = CurriculumRequirement::query()
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->where('semester', $semester)
                ->orWhereNull('semester'))
            ->whereIn('program_id', $sections->pluck('program_id')->unique()->values())
            ->whereIn('year_level_id', $sections->pluck('year_level_id')->unique()->values())
            ->orderBy('id')
            ->get(['id', 'program_id', 'year_level_id', 'semester', 'classification', 'major_id', 'subject_id']);

        $requirementsByScope = $requirements->groupBy(
            fn (CurriculumRequirement $requirement): string => $requirement->program_id.'|'.$requirement->year_level_id,
        );

        $requirementAppliesToSection = function (CurriculumRequirement $requirement, Section $section): bool {
            if ($requirement->classification !== 'major') {
                return $requirement->major_id === null;
            }

            return (int) $requirement->major_id === (int) $section->major_id;
        };

        $reportedMissingMappings = [];
        $offeringsBySection = $offerings->groupBy('section_id');

        foreach ($sections as $section) {
            $scopeKey = $section->program_id.'|'.$section->year_level_id;
            $scopeRequirements = $requirementsByScope->get($scopeKey, collect());

            if ($scopeRequirements->isEmpty()) {
                if (! isset($reportedMissingMappings[$scopeKey])) {
                    $reportedMissingMappings[$scopeKey] = true;
                    $this->addIssue(
                        $issues,
                        'warning',
                        'curriculum_mapping_missing',
                        'academic',
                        'No active curriculum mappings exist for this program, year level, and semester. Class assignments for the scope cannot be validated against a curriculum.',
                        'section',
                        (string) $section->id,
                    );
                }

                continue;
            }

            $sectionOfferings = $offeringsBySection->get($section->id, collect());

            foreach ($sectionOfferings as $offering) {
                $matches = $scopeRequirements->filter(
                    fn (CurriculumRequirement $requirement): bool => (int) $requirement->subject_id === (int) $offering->subject_id
                        && $requirementAppliesToSection($requirement, $section),
                );

                if ($matches->isEmpty()) {
                    $this->addIssue(
                        $issues,
                        'blocking',
                        'offering_subject_not_in_curriculum',
                        'academic',
                        'The class assignment subject is not mapped to the section program, year level, semester, and major in the active curriculum.',
                        'subject_offering',
                        (string) $offering->id,
                        $scopeRequirements
                            ->where('subject_id', $offering->subject_id)
                            ->pluck('id')
                            ->map(fn ($id): string => (string) $id)
                            ->values()
                            ->all(),
                    );
                }
            }

            $offeredSubjectIds = $sectionOfferings
                ->pluck('subject_id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

            $missing = $scopeRequirements
                ->filter(fn (CurriculumRequirement $requirement): bool => $requirementAppliesToSection($requirement, $section))
                ->reject(fn (CurriculumRequirement $requirement): bool => $offeredSubjectIds->has((int) $requirement->subject_id))
                ->values();

            if ($missing->isNotEmpty()) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'curriculum_offering_missing',
                    'academic',
                    'One or more curriculum-mapped subjects have no open class assignment for this section and semester.',
                    'section',
                    (string) $section->id,
                    $missing->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                );
            }
        }
    }

    private function inspectAssignments(Collection $offerings, array &$issues): Collection
    {
        $professors = collect();
        $invalidEmploymentProfessorIds = [];

        foreach ($offerings as $offering) {
            $activeAssignments = $offering->facultyAssignments;
            $eligible = $activeAssignments
                ->filter(fn ($assignment): bool => $assignment->professor !== null
                    && $assignment->professor->status === 'active'
                    && $assignment->professor->user?->status === 'active');

            if ($eligible->isEmpty()) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    $activeAssignments->isEmpty()
                        ? 'professor_assignment_missing'
                        : 'professor_assignment_not_eligible',
                    'faculty',
                    $activeAssignments->isEmpty()
                        ? 'The class assignment has no active professor assignment.'
                        : 'The class assignment has active assignment rows, but none has both an active professor profile and an active user account.',
                    'subject_offering',
                    (string) $offering->id,
                    $activeAssignments->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                );

                continue;
            }

            $distinctProfessorIds = $eligible
                ->pluck('professor_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($distinctProfessorIds->count() > 1) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'multiple_distinct_professors',
                    'faculty',
                    'The class assignment has multiple distinct active professors. Exactly one distinct professor is required.',
                    'subject_offering',
                    (string) $offering->id,
                    $distinctProfessorIds->map(fn ($id): string => (string) $id)->all(),
                );
            }

            $invalidRoleRows = $eligible
                ->reject(fn ($assignment): bool => in_array($assignment->assignment_role, self::ASSIGNMENT_ROLES, true));

            if ($invalidRoleRows->isNotEmpty()) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'professor_assignment_role_invalid',
                    'faculty',
                    'The class assignment has an active professor assignment with an unsupported assignment role.',
                    'subject_offering',
                    (string) $offering->id,
                    $invalidRoleRows->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                );
            }

            $duplicateRoleRows = $eligible
                ->groupBy(fn ($assignment): string => $assignment->professor_id.'|'.$assignment->assignment_role)
                ->filter(fn (Collection $rows): bool => $rows->count() > 1);

            if ($duplicateRoleRows->isNotEmpty()) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'duplicate_professor_assignment_role',
                    'faculty',
                    'The class assignment has duplicate active assignment rows for the same professor and role.',
                    'subject_offering',
                    (string) $offering->id,
                    $duplicateRoleRows
                        ->flatten()
                        ->pluck('id')
                        ->map(fn ($id): string => (string) $id)
                        ->values()
                        ->all(),
                );
            }

            $rolesByProfessor = $eligible
                ->groupBy(fn ($assignment): int => (int) $assignment->professor_id)
                ->map(fn (Collection $rows): array => $rows
                    ->pluck('assignment_role')
                    ->unique()
                    ->values()
                    ->all());

            $multiRoleProfessorIds = $rolesByProfessor
                ->filter(fn (array $roles): bool => count($roles) > 1)
                ->keys();

            if ($multiRoleProfessorIds->isNotEmpty() && $distinctProfessorIds->count() === 1) {
                $this->addIssue(
                    $issues,
                    'info',
                    'multiple_roles_for_same_professor',
                    'faculty',
                    'The class assignment has multiple active roles for the same distinct professor. Exactly one distinct professor is preserved; the additional roles are not treated as multiple professors.',
                    'subject_offering',
                    (string) $offering->id,
                    $multiRoleProfessorIds->map(fn ($id): string => (string) $id)->values()->all(),
                );
            }

            foreach ($eligible->groupBy('professor_id') as $professorAssignments) {
                $professor = $professorAssignments->first()->professor;

                if (! in_array($professor->employment_type, self::EMPLOYMENT_TYPES, true)
                    && ! isset($invalidEmploymentProfessorIds[$professor->id])) {
                    $invalidEmploymentProfessorIds[$professor->id] = true;
                    $this->addIssue(
                        $issues,
                        'blocking',
                        'professor_employment_type_invalid',
                        'faculty',
                        'The professor employment type is not supported by the scheduling contract.',
                        'professor',
                        (string) $professor->id,
                    );
                }

                $professors->put($professor->id, $professor);
            }
        }

        return $professors;
    }

    private function inspectAvailability(AcademicTerm $term, Collection $professors, array &$issues): void
    {
        if ($professors->isEmpty()) {
            return;
        }

        $windows = FacultyAvailability::query()
            ->where('academic_term_id', $term->id)
            ->whereIn('professor_id', $professors->keys()->all())
            ->orderBy('professor_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $byProfessor = $windows->groupBy('professor_id');
        $professorsWithoutWindows = collect();

        foreach ($professors as $professor) {
            $professorWindows = $byProfessor->get($professor->id, collect());

            if ($professorWindows->isEmpty()) {
                $professorsWithoutWindows->push($professor->id);

                continue;
            }

            $invalid = false;
            $overlap = false;

            foreach ($professorWindows->groupBy('day_of_week') as $dayWindows) {
                $previousEnd = null;

                foreach ($dayWindows as $window) {
                    $start = substr((string) $window->start_time, 0, 5);
                    $end = substr((string) $window->end_time, 0, 5);

                    if (! in_array((int) $window->day_of_week, range(1, 7), true)
                        || ! in_array($window->availability_type, ['available', 'unavailable', 'preferred'], true)
                        || $start >= $end) {
                        $invalid = true;
                    }

                    if ($previousEnd !== null && $start < $previousEnd) {
                        $overlap = true;
                    }

                    $previousEnd = $previousEnd === null ? $end : max($previousEnd, $end);
                }
            }

            if ($invalid) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'availability_window_invalid',
                    'faculty',
                    'One or more faculty availability windows are invalid.',
                    'professor',
                    (string) $professor->id,
                );
            }

            if ($overlap) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'availability_windows_overlap',
                    'faculty',
                    'Faculty availability windows overlap on the same day.',
                    'professor',
                    (string) $professor->id,
                );
            }
        }

        if ($professorsWithoutWindows->isNotEmpty()) {
            $this->addIssue(
                $issues,
                'info',
                'professor_availability_unrestricted',
                'faculty',
                'One or more assigned professors have no term availability windows. An empty window set means unrestricted availability for this contract, not missing configuration.',
                'academic_term',
                (string) $term->id,
                $professorsWithoutWindows->map(fn ($id): string => (string) $id)->values()->all(),
            );
        }
    }

    private function inspectRooms(Collection $rooms, Collection $offerings, array &$issues): void
    {
        if ($rooms->isEmpty()) {
            $this->addIssue(
                $issues,
                'blocking',
                'available_rooms_missing',
                'facilities',
                'No available rooms are configured.',
            );

            return;
        }

        $termId = $offerings->first()?->academic_term_id;
        $termEntityId = $termId === null ? null : (string) $termId;

        $usableRooms = $rooms->filter(fn ($room): bool => (int) $room->capacity >= 1
            && in_array($room->room_type, self::ROOM_TYPES, true));
        $invalidRooms = $rooms->reject(fn ($room): bool => (int) $room->capacity >= 1
            && in_array($room->room_type, self::ROOM_TYPES, true));

        if ($usableRooms->isEmpty()) {
            $this->addIssue(
                $issues,
                'blocking',
                'usable_rooms_missing',
                'facilities',
                'No available room has a usable capacity and room type.',
                'academic_term',
                $termEntityId,
                $invalidRooms->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            );
        } elseif ($invalidRooms->isNotEmpty()) {
            $this->addIssue(
                $issues,
                'warning',
                'room_configuration_invalid',
                'facilities',
                'One or more available rooms have an invalid capacity or room type and are excluded from room candidates.',
                'academic_term',
                $termEntityId,
                $invalidRooms->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            );
        }

        if ($usableRooms->isEmpty()) {
            return;
        }

        $maximumCapacity = (int) $usableRooms->max('capacity');
        $oversizedOfferings = $offerings
            ->filter(fn ($offering): bool => $offering->expected_students !== null
                && (int) $offering->expected_students > $maximumCapacity)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($oversizedOfferings !== []) {
            $this->addIssue(
                $issues,
                'warning',
                'room_capacity_risk',
                'facilities',
                'One or more class assignments have expected enrollment above every usable room capacity. This remains a warning until approved meeting plans establish whether the class assignment requires a physical room.',
                'academic_term',
                $termEntityId,
                $oversizedOfferings,
            );
        }
    }

    private function inspectPublishedSchedules(Collection $publishedSchedules, array $offeringIds, array &$issues): void
    {
        if ($publishedSchedules->isEmpty()) {
            return;
        }

        foreach ($publishedSchedules as $schedule) {
            if (! in_array((int) $schedule->subject_offering_id, $offeringIds, true)) {
                $this->addIssue(
                    $issues,
                    'blocking',
                    'published_schedule_outside_required_set',
                    'published',
                    'A published schedule belongs to a class assignment outside the authoritative required class assignment set.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }

            $professorIds = $schedule->scheduleAssignments
                ->filter(fn ($assignment): bool => $assignment->facultyAssignment !== null
                    && $assignment->facultyAssignment->status === 'active')
                ->map(fn ($assignment): int => (int) $assignment->facultyAssignment->professor_id)
                ->unique()
                ->values();

            if ($professorIds->count() > 1) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'published_schedule_multiple_professors',
                    'published',
                    'A published schedule has multiple distinct active instructors. The published baseline can record every active instructor ID; version 1 candidate meetings still use one professor per ordinary class assignment.',
                    'class_schedule',
                    (string) $schedule->id,
                    $professorIds->map(fn ($id): string => (string) $id)->all(),
                );
            }

            if ($professorIds->isEmpty()) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'published_schedule_without_professor',
                    'published',
                    'A published schedule has no active professor assignment in the baseline.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }

            $activeRooms = $schedule->roomAllocations->count();

            if (in_array($schedule->modality, ['face_to_face', 'hybrid'], true) && $activeRooms !== 1) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'published_schedule_room_allocation_mismatch',
                    'published',
                    'A physical published schedule does not have exactly one active room allocation.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }

            if ($schedule->modality === 'online' && $activeRooms > 0) {
                $this->addIssue(
                    $issues,
                    'warning',
                    'published_schedule_online_room',
                    'published',
                    'An online published schedule has an active physical room allocation.',
                    'class_schedule',
                    (string) $schedule->id,
                );
            }
        }

        $this->addIssue(
            $issues,
            'warning',
            'published_baseline_unlinked',
            'published',
            'Published schedules have no stable meeting-instance linkage, so placement retention cannot be compared reliably.',
            'academic_term',
            (string) $publishedSchedules->first()->subjectOffering->academic_term_id,
            $publishedSchedules->pluck('id')->map(fn ($id): string => (string) $id)->all(),
        );
    }

    private function inspectDraftsAndConflicts(int $draftCount, int $openConflictCount, array &$issues): void
    {
        if ($draftCount > 0) {
            $this->addIssue(
                $issues,
                'info',
                'drafts_preserved',
                'published',
                'Existing draft schedules are preserved and are not automatically selected for replacement.',
            );
        }

        if ($openConflictCount > 0) {
            $this->addIssue(
                $issues,
                'warning',
                'open_conflicts_exist',
                'published',
                'The term has open schedule conflicts. A new candidate does not resolve historical conflicts automatically.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $timeGrid
     * @param  array<int|string, array<string, mixed>>  $meetingPlans
     * @param  array<string, mixed>|null  $fullTimePolicy
     * @param  array<string, array{supported: bool, reason: string, contract_v1_behavior: string}>  $capabilityDetails
     * @return array<string, bool>
     */
    private function capabilities(
        ?array $timeGrid,
        array $meetingPlans,
        Collection $offerings,
        Collection $professors,
        ?array $fullTimePolicy,
        array $capabilityDetails,
    ): array {
        $plansComplete = $offerings->isNotEmpty() && $offerings->every(
            fn (SubjectOffering $offering): bool => $this->isValidMeetingPlanPayload(
                $this->meetingPlanFor($meetingPlans, $offering->id),
            ),
        );

        $fullTimeProfessors = $professors->filter(fn ($professor): bool => $professor->employment_type === 'full_time');
        $partTimeProfessors = $professors->filter(fn ($professor): bool => $professor->employment_type === 'part_time');
        $partTimeTargetsComplete = $partTimeProfessors->isNotEmpty() && $partTimeProfessors->every(
            fn ($professor): bool => $this->configuration->partTimeWeeklyTargetMinutes($professor) !== null,
        );

        return [
            'time_grid' => $this->isValidTimeGridPayload($timeGrid),
            'meeting_plans' => $plansComplete,
            'full_time_workday_policy' => $fullTimeProfessors->isEmpty()
                || $this->isValidFullTimePolicyPayload($fullTimePolicy),
            'part_time_workload_targets' => $partTimeProfessors->isEmpty() || $partTimeTargetsComplete,
            'section_availability' => ($capabilityDetails['section_availability']['supported'] ?? false) === true,
            'combined_classes' => ($capabilityDetails['combined_classes']['supported'] ?? false) === true,
            'fixed_schedule_protection' => ($capabilityDetails['fixed_schedule_ids']['supported'] ?? false) === true,
            'replacement_draft_selection' => ($capabilityDetails['replacement_draft_schedule_ids']['supported'] ?? false) === true,
            'existing_commitments' => ($capabilityDetails['existing_commitments']['supported'] ?? false) === true,
            'generation_records' => $this->schemaHasTable('scheduling_generations'),
            'snapshot_history' => $this->schemaHasTable('scheduling_snapshots'),
            'candidate_persistence' => $this->schemaHasTable('scheduling_candidates')
                && $this->schemaHasColumn('scheduling_candidates', 'source_response_sha256'),
            'candidate_revisions' => $this->schemaHasTable('scheduling_candidate_revisions')
                && $this->schemaHasTable('candidate_revision_status_events'),
            'published_meeting_instance_linkage' => false,
        ];
    }

    private function schemaHasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function schemaHasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $meetingPlans
     * @return array<string, mixed>|null
     */
    private function meetingPlanFor(array $meetingPlans, int $offeringId): ?array
    {
        $plan = $meetingPlans[$offeringId] ?? $meetingPlans[(string) $offeringId] ?? null;

        return is_array($plan) ? $plan : null;
    }

    private function isValidTimeGridPayload(?array $timeGrid): bool
    {
        if ($timeGrid === null || ! is_array($timeGrid['days'] ?? null) || $timeGrid['days'] === []) {
            return false;
        }

        foreach ($timeGrid['days'] as $day) {
            if (! is_array($day)) {
                return false;
            }

            $dayOfWeek = $day['day_of_week'] ?? null;
            $start = $day['operating_start'] ?? null;
            $end = $day['operating_end'] ?? null;
            $validStarts = $day['valid_start_times'] ?? null;

            if (! is_int($dayOfWeek) || $dayOfWeek < 1 || $dayOfWeek > 5
                || ! is_string($start) || $start === ''
                || ! is_string($end) || $end === ''
                || $start >= $end
                || ! is_array($validStarts) || $validStarts === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    private function isValidMeetingPlanPayload(?array $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        $requirements = $plan['meeting_requirements'] ?? null;

        if (! is_array($requirements) || $requirements === []) {
            return false;
        }

        $hasMandatory = false;

        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                return false;
            }

            $hasMandatory = $hasMandatory || (bool) ($requirement['mandatory'] ?? false);

            if (! is_string($requirement['id'] ?? null) || $requirement['id'] === ''
                || ! in_array($requirement['meeting_type'] ?? null, ['lecture', 'laboratory', 'seminar', 'other'], true)
                || ! in_array($requirement['modality'] ?? null, ['face_to_face', 'online', 'hybrid'], true)
                || ! is_int($requirement['frequency_per_week'] ?? null)
                || $requirement['frequency_per_week'] < 1
                || ! is_int($requirement['duration_minutes'] ?? null)
                || $requirement['duration_minutes'] < 1) {
                return false;
            }
        }

        return $hasMandatory;
    }

    /**
     * @param  array<string, mixed>|null  $policy
     */
    private function isValidFullTimePolicyPayload(?array $policy): bool
    {
        if ($policy === null
            || ! is_string($policy['policy_id'] ?? null)
            || ($policy['policy_id'] ?? '') === ''
            || ! is_int($policy['policy_version'] ?? null)
            || ($policy['policy_version'] ?? 0) < 1) {
            return false;
        }

        $standardDays = $policy['standard_days'] ?? null;
        $exceptions = $policy['exceptions'] ?? null;

        if (! $this->isValidWeekdayList($standardDays) || ! is_array($exceptions) || ! array_is_list($exceptions)) {
            return false;
        }

        foreach ($exceptions as $exception) {
            if (! is_array($exception)
                || ! is_string($exception['professor_id'] ?? null)
                || ($exception['professor_id'] ?? '') === ''
                || ! $this->isValidWeekdayList($exception['authorized_additional_days'] ?? null)
                || array_intersect($exception['authorized_additional_days'], $standardDays) !== []) {
                return false;
            }
        }

        return true;
    }

    private function isValidWeekdayList(mixed $days): bool
    {
        if (! is_array($days) || $days === [] || ! array_is_list($days)) {
            return false;
        }

        foreach ($days as $day) {
            if (! is_int($day) || $day < 1 || $day > 5) {
                return false;
            }
        }

        return $days === array_values(array_unique($days));
    }

    private function inspectCapabilities(
        AcademicTerm $term,
        Collection $offerings,
        Collection $professors,
        ?array $timeGrid,
        array $meetingPlans,
        array $capabilities,
        array $capabilityDetails,
        array &$issues,
    ): void {
        $termId = (string) $term->id;

        if ($timeGrid === null) {
            $this->addIssue(
                $issues,
                'blocking',
                'time_grid_not_configured',
                'meetings',
                'No approved term-scoped time grid exists. Open Configuration, create a Monday-to-Friday time grid, and have an administrator approve it.',
                'academic_term',
                $termId,
            );
        } elseif ($capabilities['time_grid'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'time_grid_invalid',
                'meetings',
                'The approved time grid has no usable days or fixed start times. Open Configuration and create a new approved revision.',
                'academic_term',
                $termId,
            );
        }

        $missingPlanIds = $offerings
            ->filter(fn (SubjectOffering $offering): bool => $this->meetingPlanFor($meetingPlans, $offering->id) === null)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($missingPlanIds !== []) {
            $this->addIssue(
                $issues,
                'blocking',
                'meeting_plans_not_configured',
                'meetings',
                'One or more open class assignments have no approved meeting plan. Open Configuration and approve a plan with lecture and/or laboratory requirements.',
                'academic_term',
                $termId,
                $missingPlanIds,
            );
        }

        $invalidPlanIds = $offerings
            ->filter(function (SubjectOffering $offering) use ($meetingPlans): bool {
                $plan = $this->meetingPlanFor($meetingPlans, $offering->id);

                return $plan !== null && ! $this->isValidMeetingPlanPayload($plan);
            })
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($invalidPlanIds !== []) {
            $this->addIssue(
                $issues,
                'blocking',
                'meeting_plan_invalid',
                'meetings',
                'One or more approved meeting plans have no valid meeting requirements. Open Configuration and create a corrected revision.',
                'academic_term',
                $termId,
                $invalidPlanIds,
            );
        }

        $fullTimeProfessorIds = $professors
            ->where('employment_type', 'full_time')
            ->keys()
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($capabilities['full_time_workday_policy'] !== true && $fullTimeProfessorIds !== []) {
            $this->addIssue(
                $issues,
                'blocking',
                'full_time_workday_policy_not_configured',
                'faculty',
                'Full-time professors are assigned, but no term-scoped workday policy or authorized exceptions exist.',
                'academic_term',
                $termId,
                $fullTimeProfessorIds,
            );
        }

        $partTimeProfessorIds = $professors
            ->where('employment_type', 'part_time')
            ->keys()
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($capabilities['part_time_workload_targets'] !== true && $partTimeProfessorIds !== []) {
            $this->addIssue(
                $issues,
                'blocking',
                'part_time_targets_not_configured',
                'faculty',
                'Part-time professors are assigned, but weekly teaching-hour targets are not stored.',
                'academic_term',
                $termId,
                $partTimeProfessorIds,
            );
        }

        if ($capabilities['section_availability'] !== true) {
            $this->addIssue(
                $issues,
                'info',
                'section_availability_not_configured',
                'academic',
                (string) ($capabilityDetails['section_availability']['reason'] ?? 'Section availability is unsupported in Contract v1.0.'),
                'academic_term',
                $termId,
            );
        }

        if ($capabilities['combined_classes'] !== true) {
            $this->addIssue(
                $issues,
                'info',
                'combined_classes_not_configured',
                'academic',
                (string) ($capabilityDetails['combined_classes']['reason'] ?? 'Combined classes are unsupported in Contract v1.0.'),
                'academic_term',
                $termId,
            );
        }

        if (($capabilities['fixed_schedule_protection'] ?? false) !== true) {
            $this->addIssue(
                $issues,
                'info',
                'fixed_schedule_protection_not_supported',
                'scheduling',
                (string) ($capabilityDetails['fixed_schedule_ids']['reason'] ?? 'Fixed schedules are unsupported in Contract v1.0.'),
                'academic_term',
                $termId,
            );
        }

        if (($capabilities['replacement_draft_selection'] ?? false) !== true) {
            $this->addIssue(
                $issues,
                'info',
                'replacement_draft_selection_not_supported',
                'scheduling',
                (string) ($capabilityDetails['replacement_draft_schedule_ids']['reason'] ?? 'Replacement draft selection is unsupported in Contract v1.0.'),
                'academic_term',
                $termId,
            );
        }

        if (($capabilities['existing_commitments'] ?? false) !== true) {
            $this->addIssue(
                $issues,
                'info',
                'existing_commitments_not_supported',
                'scheduling',
                (string) ($capabilityDetails['existing_commitments']['reason'] ?? 'Existing commitments are unsupported in Contract v1.0.'),
                'academic_term',
                $termId,
            );
        }

        if ($capabilities['generation_records'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'generation_records_not_configured',
                'workflow',
                'No scheduling generation records exist. A generation cannot be tracked, scoped, or linked to a solver attempt.',
                'academic_term',
                $termId,
            );
        }

        if ($capabilities['snapshot_history'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'snapshot_history_not_configured',
                'workflow',
                'No immutable snapshot history exists. The approved input hash cannot be retained, verified, or audited.',
                'academic_term',
                $termId,
            );
        }

        if ($capabilities['candidate_persistence'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'candidate_persistence_not_configured',
                'workflow',
                'No immutable candidate persistence exists. A solver result cannot be stored, reviewed, or audited safely.',
                'academic_term',
                $termId,
            );
        }

        if (($capabilities['candidate_revisions'] ?? false) !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'candidate_revision_history_not_configured',
                'workflow',
                'No candidate revision history exists. Original solver output cannot be preserved separately from reviewed revisions.',
                'academic_term',
                $termId,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array{
     *     ready: bool,
     *     blocking_count: int,
     *     warning_count: int,
     *     info_count: int,
     *     issues: list<array<string, mixed>>
     * }
     */
    private function layerSummary(array $issues, string $layer): array
    {
        $layerIssues = array_values(array_filter(
            $issues,
            fn (array $issue): bool => $issue['layer'] === $layer,
        ));

        return [
            'ready' => $this->countSeverity($layerIssues, 'blocking') === 0,
            'blocking_count' => $this->countSeverity($layerIssues, 'blocking'),
            'warning_count' => $this->countSeverity($layerIssues, 'warning'),
            'info_count' => $this->countSeverity($layerIssues, 'info'),
            'issues' => $layerIssues,
        ];
    }

    private function addIssue(
        array &$issues,
        string $severity,
        string $code,
        string $category,
        string $message,
        ?string $entityType = null,
        ?string $entityId = null,
        array $relatedIds = [],
    ): void {
        $issues[] = [
            'severity' => $severity,
            'code' => $code,
            'category' => $category,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'related_ids' => array_values($relatedIds),
        ];
    }

    private function countSeverity(array $issues, string $severity): int
    {
        return collect($issues)
            ->where('severity', $severity)
            ->count();
    }
}
