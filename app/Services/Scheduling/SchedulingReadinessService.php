<?php

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\ScheduleConflict;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SchedulingReadinessService
{
    private const CAPABILITIES = [
        'time_grid' => false,
        'meeting_plans' => false,
        'full_time_workday_policy' => false,
        'part_time_workload_targets' => false,
        'section_availability' => false,
        'combined_classes' => false,
        'generation_records' => false,
        'snapshot_history' => false,
        'candidate_persistence' => false,
        'published_meeting_instance_linkage' => false,
    ];

    private const GENERATION_REQUIRED_CAPABILITIES = [
        'time_grid',
        'meeting_plans',
        'generation_records',
        'snapshot_history',
        'candidate_persistence',
    ];

    private const ASSIGNMENT_ROLES = ['lead', 'co_instructor', 'lab_instructor', 'substitute'];

    private const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'visiting'];

    private const ROOM_TYPES = ['lecture', 'laboratory', 'seminar', 'office', 'other'];

    public function inspect(AcademicTerm $term): array
    {
        $offerings = SubjectOffering::query()
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

        $this->inspectTerm($term, $issues);
        $this->inspectOfferings($term, $offerings, $issues);
        $assignedProfessors = $this->inspectAssignments($offerings, $issues);
        $this->inspectAvailability($term, $assignedProfessors, $issues);
        $this->inspectRooms($rooms, $offerings, $issues);
        $this->inspectPublishedSchedules($publishedSchedules, $offeringIds, $issues);
        $this->inspectDraftsAndConflicts($draftCount, $openConflictCount, $issues);
        $this->inspectCapabilities($term, $offerings, $assignedProfessors, $issues);

        $blockingCount = $this->countSeverity($issues, 'blocking');
        $warningCount = $this->countSeverity($issues, 'warning');
        $infoCount = $this->countSeverity($issues, 'info');
        $capabilityGap = ! $this->hasRequiredGenerationCapabilities();

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
            'generation_available' => $blockingCount === 0 && ! $capabilityGap,
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
            'capabilities' => self::CAPABILITIES,
            'inspected_at' => now()->toIso8601String(),
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
                'No open subject offerings are configured for this academic term.',
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
            'The required offering set is every open offering for the term. CCSync has no curriculum source to cross-check for missing offerings.',
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
                    'The offering section does not belong to the selected academic term.',
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
                    'The offering section must be active.',
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
                    'The offering subject must be active.',
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
                    'The offering program must be active.',
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
                        ? 'The offering has no active professor assignment.'
                        : 'The offering has active assignment rows, but none has both an active professor profile and an active user account.',
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
                    'The offering has multiple distinct active professors. Exactly one distinct professor is required.',
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
                    'The offering has an active professor assignment with an unsupported assignment role.',
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
                    'The offering has duplicate active assignment rows for the same professor and role.',
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
                    'The offering has multiple active roles for the same distinct professor. Exactly one distinct professor is preserved; the additional roles are not treated as multiple professors.',
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
                'One or more offerings have expected enrollment above every usable room capacity. This remains a warning until approved meeting plans establish whether the offering requires a physical room.',
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
                    'A published schedule belongs to an offering outside the authoritative required offering set.',
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
                    'A published schedule has multiple distinct active instructors. The published baseline can record every active instructor ID; version 1 candidate meetings still use one professor per ordinary offering.',
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

    private function inspectCapabilities(
        AcademicTerm $term,
        Collection $offerings,
        Collection $professors,
        array &$issues,
    ): void {
        $termId = (string) $term->id;

        if (self::CAPABILITIES['time_grid'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'time_grid_not_configured',
                'meetings',
                'No approved term-scoped time grid exists. Applicable days, operating hours, timezone, and valid start times cannot be supplied.',
                'academic_term',
                $termId,
            );
        }

        if (self::CAPABILITIES['meeting_plans'] !== true && $offerings->isNotEmpty()) {
            $this->addIssue(
                $issues,
                'blocking',
                'meeting_plans_not_configured',
                'meetings',
                'No approved offering meeting plans exist. Frequency, duration, modality, room type, and mandatory occurrences cannot be supplied.',
                'academic_term',
                $termId,
                $offerings->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        }

        $fullTimeProfessorIds = $professors
            ->where('employment_type', 'full_time')
            ->keys()
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if (self::CAPABILITIES['full_time_workday_policy'] !== true && $fullTimeProfessorIds !== []) {
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

        if (self::CAPABILITIES['part_time_workload_targets'] !== true && $partTimeProfessorIds !== []) {
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

        if (self::CAPABILITIES['section_availability'] !== true) {
            $this->addIssue(
                $issues,
                'info',
                'section_availability_not_configured',
                'academic',
                'Section availability is not stored. The contract can represent an empty restriction list, but no term-specific section windows can be supplied.',
                'academic_term',
                $termId,
            );
        }

        if (self::CAPABILITIES['combined_classes'] !== true) {
            $this->addIssue(
                $issues,
                'info',
                'combined_classes_not_configured',
                'academic',
                'Explicit combined-class groups are not stored. No combined classes can be included in a snapshot.',
                'academic_term',
                $termId,
            );
        }

        if (self::CAPABILITIES['generation_records'] !== true) {
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

        if (self::CAPABILITIES['snapshot_history'] !== true) {
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

        if (self::CAPABILITIES['candidate_persistence'] !== true) {
            $this->addIssue(
                $issues,
                'blocking',
                'candidate_persistence_not_configured',
                'workflow',
                'No candidate persistence exists. A solver result cannot be stored, reviewed, approved, or published safely.',
                'academic_term',
                $termId,
            );
        }
    }

    private function hasRequiredGenerationCapabilities(): bool
    {
        foreach (self::GENERATION_REQUIRED_CAPABILITIES as $capability) {
            if ((self::CAPABILITIES[$capability] ?? false) !== true) {
                return false;
            }
        }

        return true;
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
