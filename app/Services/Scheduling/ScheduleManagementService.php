<?php

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Room;
use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleConflict;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleManagementService
{
    private const OFFERING_RELATIONS = [
        'academicTerm',
        'section.program',
        'section.yearLevel',
        'subject',
    ];

    public function createSchedule(array $attributes): ClassSchedule
    {
        return DB::transaction(function () use ($attributes): ClassSchedule {
            $offering = $this->lockOffering($attributes['subject_offering_id']);
            $this->assertOfferingSchedulable($offering);
            $this->assertValidScheduleWindow($offering, $attributes);
            if (ClassSchedule::query()
                ->where('subject_offering_id', $offering->id)
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('start_time', $attributes['start_time'])
                ->where('end_time', $attributes['end_time'])
                ->where('schedule_type', $attributes['schedule_type'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'start_time' => 'A schedule with this offering, day, time, and type already exists.',
                ]);
            }

            return ClassSchedule::create([
                ...$attributes,
                'status' => 'draft',
            ]);
        }, attempts: 3);
    }

    public function updateDraftSchedule(ClassSchedule $schedule, array $attributes): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $attributes): ClassSchedule {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $offering = $this->lockOffering($lockedSchedule->subject_offering_id);
            $this->assertOfferingSchedulable($offering);
            $nextAttributes = [
                ...$lockedSchedule->only(['day_of_week', 'start_time', 'end_time', 'schedule_type', 'modality']),
                ...$attributes,
            ];
            $this->assertValidScheduleWindow($offering, $nextAttributes);
            if (ClassSchedule::query()
                ->where('subject_offering_id', $lockedSchedule->subject_offering_id)
                ->where('day_of_week', $nextAttributes['day_of_week'])
                ->where('start_time', $nextAttributes['start_time'])
                ->where('end_time', $nextAttributes['end_time'])
                ->where('schedule_type', $nextAttributes['schedule_type'])
                ->whereKeyNot($lockedSchedule->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'start_time' => 'A schedule with this offering, day, time, and type already exists.',
                ]);
            }
            $lockedSchedule->update($attributes);
            if ($nextAttributes['modality'] === 'online') {
                $lockedSchedule->roomAllocations()->where('status', 'active')->update([
                    'status' => 'released',
                    'released_at' => now(),
                ]);
            }

            return $lockedSchedule->fresh();
        }, attempts: 3);
    }

    public function createFacultyAssignment(array $attributes, User $actor): FacultyAssignment
    {
        return DB::transaction(function () use ($attributes, $actor): FacultyAssignment {
            $offering = $this->lockOffering($attributes['subject_offering_id']);
            $this->assertOfferingSchedulable($offering);
            $professor = Professor::query()->lockForUpdate()->with('user')->findOrFail($attributes['professor_id']);
            $this->assertProfessorActive($professor);

            if ($attributes['assignment_role'] === 'lead' && FacultyAssignment::query()
                ->where('subject_offering_id', $offering->id)
                ->where('assignment_role', 'lead')
                ->where('status', 'active')
                ->where('professor_id', '<>', $professor->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'assignment_role' => 'This offering already has an active lead instructor.',
                ]);
            }

            $assignment = FacultyAssignment::query()
                ->where('professor_id', $professor->id)
                ->where('subject_offering_id', $offering->id)
                ->where('assignment_role', $attributes['assignment_role'])
                ->first();

            if ($assignment !== null && $assignment->status !== 'cancelled') {
                throw ValidationException::withMessages([
                    'professor_id' => 'This professor already has an assignment with that role for the offering.',
                ]);
            }

            if ($assignment === null) {
                $assignment = FacultyAssignment::create([
                    ...$attributes,
                    'assigned_by_user_id' => $actor->id,
                    'status' => 'active',
                ]);
            } else {
                $assignment->update([
                    'assigned_units' => $attributes['assigned_units'] ?? null,
                    'assigned_by_user_id' => $actor->id,
                    'status' => 'active',
                ]);
            }

            return $assignment->fresh();
        }, attempts: 3);
    }

    public function updateFacultyAssignmentStatus(FacultyAssignment $assignment, string $status): FacultyAssignment
    {
        return DB::transaction(function () use ($assignment, $status): FacultyAssignment {
            $this->lockOffering($assignment->subject_offering_id);
            $lockedAssignment = FacultyAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($status !== 'active' && $lockedAssignment->scheduleAssignments()
                ->where('status', 'active')
                ->whereHas('classSchedule', fn (Builder $query) => $query->where('status', 'published'))
                ->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Cancel published schedules before cancelling this faculty assignment.',
                ]);
            }

            $lockedAssignment->update(['status' => $status]);

            return $lockedAssignment->fresh();
        }, attempts: 3);
    }

    public function assignFaculty(
        ClassSchedule $schedule,
        int $facultyAssignmentId,
        bool $primary,
    ): ScheduleAssignment {
        return DB::transaction(function () use ($schedule, $facultyAssignmentId, $primary): ScheduleAssignment {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $facultyAssignment = FacultyAssignment::query()
                ->with('professor.user')
                ->lockForUpdate()
                ->findOrFail($facultyAssignmentId);

            if ($facultyAssignment->subject_offering_id !== $lockedSchedule->subject_offering_id) {
                throw ValidationException::withMessages([
                    'faculty_assignment_id' => 'The faculty assignment must belong to this schedule’s subject offering.',
                ]);
            }

            if ($facultyAssignment->status !== 'active') {
                throw ValidationException::withMessages([
                    'faculty_assignment_id' => 'Only active faculty assignments can be scheduled.',
                ]);
            }
            $this->assertProfessorActive($facultyAssignment->professor);

            if ($primary) {
                $lockedSchedule->scheduleAssignments()
                    ->where('status', 'active')
                    ->update(['is_primary' => false]);
            }

            $assignment = $lockedSchedule->scheduleAssignments()
                ->where('faculty_assignment_id', $facultyAssignment->id)
                ->first();

            if ($assignment === null) {
                return $lockedSchedule->scheduleAssignments()->create([
                    'faculty_assignment_id' => $facultyAssignment->id,
                    'is_primary' => $primary,
                    'status' => 'active',
                ]);
            }

            $assignment->update([
                'is_primary' => $primary,
                'status' => 'active',
            ]);

            return $assignment->fresh();
        }, attempts: 3);
    }

    public function cancelScheduleAssignment(ClassSchedule $schedule, ScheduleAssignment $assignment): ScheduleAssignment
    {
        return DB::transaction(function () use ($schedule, $assignment): ScheduleAssignment {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $lockedAssignment = $lockedSchedule->scheduleAssignments()
                ->lockForUpdate()
                ->findOrFail($assignment->id);
            $lockedAssignment->update(['status' => 'cancelled', 'is_primary' => false]);

            return $lockedAssignment->fresh();
        }, attempts: 3);
    }

    public function allocateRoom(ClassSchedule $schedule, int $roomId): RoomAllocation
    {
        return DB::transaction(function () use ($schedule, $roomId): RoomAllocation {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $offering = $this->lockOffering($lockedSchedule->subject_offering_id);
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);

            if ($lockedSchedule->modality === 'online') {
                throw ValidationException::withMessages([
                    'room_id' => 'Online schedules cannot be assigned a physical room.',
                ]);
            }

            $this->assertRoomFitsOffering($room, $offering);

            $lockedSchedule->roomAllocations()
                ->where('status', 'active')
                ->update(['status' => 'released', 'released_at' => now()]);

            $allocation = $lockedSchedule->roomAllocations()
                ->where('room_id', $room->id)
                ->first();

            if ($allocation === null) {
                return $lockedSchedule->roomAllocations()->create([
                    'room_id' => $room->id,
                    'status' => 'active',
                    'allocated_at' => now(),
                ]);
            }

            $allocation->update([
                'status' => 'active',
                'allocated_at' => now(),
                'released_at' => null,
            ]);

            return $allocation->fresh();
        }, attempts: 3);
    }

    public function releaseRoom(ClassSchedule $schedule, RoomAllocation $allocation): RoomAllocation
    {
        return DB::transaction(function () use ($schedule, $allocation): RoomAllocation {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $lockedAllocation = $lockedSchedule->roomAllocations()
                ->lockForUpdate()
                ->findOrFail($allocation->id);

            if ($lockedAllocation->status === 'active') {
                $lockedAllocation->update(['status' => 'released', 'released_at' => now()]);
            }

            return $lockedAllocation->fresh();
        }, attempts: 3);
    }

    public function previewConflicts(ClassSchedule $schedule): array
    {
        return $this->findConflicts($schedule);
    }

    public function publish(ClassSchedule $schedule): array
    {
        return DB::transaction(function () use ($schedule): array {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            $this->assertDraft($lockedSchedule);
            $offering = $this->lockOffering($lockedSchedule->subject_offering_id);
            $this->assertOfferingSchedulable($offering);
            $this->assertReadyToPublish($lockedSchedule, $offering);

            $conflicts = $this->findConflicts($lockedSchedule);
            $blockingConflicts = [];
            foreach ($conflicts as $conflict) {
                $record = $this->recordConflict($lockedSchedule, $conflict);
                if ($record->status !== 'waived') {
                    $blockingConflicts[] = $conflict;
                }
            }

            if ($blockingConflicts !== []) {
                return ['schedule' => $lockedSchedule, 'conflicts' => $blockingConflicts];
            }

            $lockedSchedule->update(['status' => 'published']);
            $lockedSchedule->conflicts()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_notes' => 'Schedule published after the conflict was removed.',
            ]);
            $lockedSchedule->conflictingRecords()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_notes' => 'Schedule published after the conflict was removed.',
            ]);

            return ['schedule' => $lockedSchedule->fresh(), 'conflicts' => []];
        }, attempts: 3);
    }

    public function cancel(ClassSchedule $schedule, User $actor): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $actor): ClassSchedule {
            $lockedSchedule = $this->lockSchedule($schedule->id);
            if ($lockedSchedule->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'This schedule is already cancelled.']);
            }

            $lockedSchedule->update(['status' => 'cancelled']);
            $lockedSchedule->scheduleAssignments()->where('status', 'active')
                ->update(['status' => 'cancelled', 'is_primary' => false]);
            $lockedSchedule->roomAllocations()->where('status', 'active')
                ->update(['status' => 'cancelled', 'released_at' => now()]);
            $notes = 'Schedule cancelled.';
            $lockedSchedule->conflicts()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
            ]);
            $lockedSchedule->conflictingRecords()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
            ]);

            return $lockedSchedule->fresh();
        }, attempts: 3);
    }

    public function resolveConflict(ScheduleConflict $conflict, User $actor, string $notes): ScheduleConflict
    {
        return DB::transaction(function () use ($conflict, $actor, $notes): ScheduleConflict {
            $summary = ScheduleConflict::query()
                ->with('classSchedule.subjectOffering:id,academic_term_id,section_id')
                ->findOrFail($conflict->id);
            $this->lockTermAndSection(
                $summary->classSchedule->subjectOffering->academic_term_id,
                $summary->classSchedule->subjectOffering->section_id,
            );
            $lockedConflict = ScheduleConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($lockedConflict->status !== 'open') {
                throw ValidationException::withMessages([
                    'resolution_notes' => 'Only open conflicts can be resolved.',
                ]);
            }
            $stillConflicts = $lockedConflict->classSchedule->status === 'published'
                && $lockedConflict->conflictingClassSchedule->status === 'published'
                && collect($this->findConflicts($lockedConflict->classSchedule))
                    ->contains(fn (array $candidate): bool => $candidate['schedule_id'] === $lockedConflict->conflicting_class_schedule_id
                        && $candidate['conflict_type'] === $lockedConflict->conflict_type);

            if ($stillConflicts) {
                throw ValidationException::withMessages([
                    'resolution_notes' => 'Resolve the live scheduling overlap before marking this conflict resolved.',
                ]);
            }

            $lockedConflict->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
            ]);

            return $lockedConflict->fresh();
        }, attempts: 3);
    }

    public function waiveConflict(ScheduleConflict $conflict, User $actor, string $notes): ScheduleConflict
    {
        DB::transaction(function () use ($conflict, $actor, $notes): void {
            $summary = ScheduleConflict::query()
                ->with('classSchedule.subjectOffering:id,academic_term_id,section_id')
                ->findOrFail($conflict->id);
            $this->lockTermAndSection(
                $summary->classSchedule->subjectOffering->academic_term_id,
                $summary->classSchedule->subjectOffering->section_id,
            );
            $lockedConflict = ScheduleConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($lockedConflict->status !== 'open') {
                throw ValidationException::withMessages([
                    'resolution_notes' => 'Only open conflicts can be waived.',
                ]);
            }
            $lockedConflict->update([
                'status' => 'waived',
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
            ]);
        }, attempts: 3);

        return $conflict->fresh();
    }

    public function findConflicts(ClassSchedule $schedule): array
    {
        $schedule->loadMissing([
            'subjectOffering:id,academic_term_id,section_id',
            'scheduleAssignments.facultyAssignment',
            'roomAllocations.room',
        ]);
        $termId = $schedule->subjectOffering->academic_term_id;
        $sectionId = $schedule->subjectOffering->section_id;
        $professorIds = $schedule->scheduleAssignments
            ->where('status', 'active')
            ->filter(fn (ScheduleAssignment $assignment): bool => $assignment->facultyAssignment?->status === 'active')
            ->map(fn (ScheduleAssignment $assignment): int => $assignment->facultyAssignment->professor_id)
            ->unique()
            ->values();
        $roomIds = $schedule->roomAllocations
            ->where('status', 'active')
            ->map(fn (RoomAllocation $allocation): int => $allocation->room_id)
            ->unique()
            ->values();

        $overlapping = ClassSchedule::query()
            ->with(['subjectOffering:id,academic_term_id,section_id'])
            ->where('id', '<>', $schedule->id)
            ->where('status', 'published')
            ->where('day_of_week', $schedule->day_of_week)
            ->where('start_time', '<', $schedule->end_time)
            ->where('end_time', '>', $schedule->start_time)
            ->whereHas('subjectOffering', fn (Builder $query) => $query->where('academic_term_id', $termId))
            ->get();

        $conflicts = [];
        foreach ($overlapping as $other) {
            if ($other->subjectOffering->section_id === $sectionId) {
                $conflicts[] = $this->conflictPayload($other, 'section', sectionId: $sectionId);
            }

            if ($professorIds->isNotEmpty()) {
                $sharedProfessor = $this->sharedProfessor($other, $professorIds);
                if ($sharedProfessor !== null) {
                    $conflicts[] = $this->conflictPayload($other, 'faculty', professorId: $sharedProfessor);
                }
            }

            if ($roomIds->isNotEmpty()) {
                $sharedRoom = $other->roomAllocations()
                    ->where('status', 'active')
                    ->whereIn('room_id', $roomIds)
                    ->value('room_id');
                if ($sharedRoom !== null) {
                    $conflicts[] = $this->conflictPayload($other, 'room', roomId: (int) $sharedRoom);
                }
            }
        }

        return $conflicts;
    }

    private function lockSchedule(int $scheduleId): ClassSchedule
    {
        $summary = ClassSchedule::query()->with('subjectOffering:id,academic_term_id,section_id')->findOrFail($scheduleId);
        $this->lockTermAndSection($summary->subjectOffering->academic_term_id, $summary->subjectOffering->section_id);

        return ClassSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
    }

    private function lockOffering(int $offeringId): SubjectOffering
    {
        $summary = SubjectOffering::query()->findOrFail($offeringId);
        $this->lockTermAndSection($summary->academic_term_id, $summary->section_id);

        return SubjectOffering::query()
            ->with(self::OFFERING_RELATIONS)
            ->lockForUpdate()
            ->findOrFail($offeringId);
    }

    private function lockTermAndSection(int $termId, int $sectionId): void
    {
        AcademicTerm::query()->lockForUpdate()->findOrFail($termId);
        Section::query()->lockForUpdate()->findOrFail($sectionId);
    }

    private function assertDraft(ClassSchedule $schedule): void
    {
        if ($schedule->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft schedules can be edited. Cancel a published schedule and create a replacement.',
            ]);
        }
    }

    private function assertOfferingSchedulable(SubjectOffering $offering): void
    {
        if ($offering->status !== 'open'
            || $offering->section->status !== 'active'
            || ! in_array($offering->academicTerm->status, ['active', 'planned'], true)
            || $offering->subject->status !== 'active'
            || $offering->section->program->status !== 'active') {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'The offering, section, program, subject, and term must be active or planned for scheduling.',
            ]);
        }

        if ($offering->section->academic_term_id !== $offering->academic_term_id) {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'The offering term must match its section term.',
            ]);
        }
    }

    private function assertValidScheduleWindow(SubjectOffering $offering, array $attributes): void
    {
        if (substr((string) $attributes['start_time'], 0, 5) >= substr((string) $attributes['end_time'], 0, 5)) {
            throw ValidationException::withMessages([
                'end_time' => 'The schedule end time must be later than its start time.',
            ]);
        }

        $firstScheduledDay = CarbonImmutable::parse($offering->academicTerm->starts_on);
        $daysUntilOccurrence = ((int) $attributes['day_of_week'] - $firstScheduledDay->dayOfWeekIso + 7) % 7;
        if ($firstScheduledDay->addDays($daysUntilOccurrence)->gt(CarbonImmutable::parse($offering->academicTerm->ends_on))) {
            throw ValidationException::withMessages([
                'day_of_week' => 'The selected weekday does not occur within the academic term.',
            ]);
        }
    }

    private function assertReadyToPublish(ClassSchedule $schedule, SubjectOffering $offering): void
    {
        $schedule->load([
            'scheduleAssignments.facultyAssignment.professor.user',
            'roomAllocations.room',
        ]);
        $assignments = $schedule->scheduleAssignments
            ->where('status', 'active')
            ->filter(fn (ScheduleAssignment $assignment): bool => $assignment->facultyAssignment?->status === 'active');
        $primary = $assignments->where('is_primary', true);
        if ($assignments->isEmpty() || $primary->count() !== 1) {
            throw ValidationException::withMessages([
                'assignments' => 'Assign at least one active instructor and exactly one primary instructor before publishing.',
            ]);
        }

        foreach ($assignments as $assignment) {
            if ($assignment->facultyAssignment->subject_offering_id !== $schedule->subject_offering_id) {
                throw ValidationException::withMessages([
                    'assignments' => 'Every scheduled instructor must be assigned to this subject offering.',
                ]);
            }
            $professor = Professor::query()->lockForUpdate()
                ->with('user')
                ->findOrFail($assignment->facultyAssignment->professor_id);
            $this->assertProfessorActive($professor);
            $this->assertProfessorAvailability($professor, $offering, $schedule);
        }

        $rooms = $schedule->roomAllocations->where('status', 'active');
        if (in_array($schedule->modality, ['face_to_face', 'hybrid'], true) && $rooms->count() !== 1) {
            throw ValidationException::withMessages([
                'room_allocations' => 'Face-to-face and hybrid schedules require exactly one active room.',
            ]);
        }
        if ($schedule->modality === 'online' && $rooms->isNotEmpty()) {
            throw ValidationException::withMessages([
                'room_allocations' => 'Online schedules cannot have a physical room allocation.',
            ]);
        }

        foreach ($rooms as $allocation) {
            $this->assertRoomFitsOffering($allocation->room, $offering);
        }
    }

    private function assertProfessorActive(Professor $professor): void
    {
        $professor->loadMissing('user');
        if ($professor->status !== 'active' || $professor->user->status !== 'active') {
            throw ValidationException::withMessages([
                'professor_id' => 'The professor profile and user account must both be active.',
            ]);
        }
    }

    private function assertProfessorAvailability(
        Professor $professor,
        SubjectOffering $offering,
        ClassSchedule $schedule,
    ): void {
        $windows = $professor->facultyAvailability()
            ->where('academic_term_id', $offering->academic_term_id)
            ->where('day_of_week', $schedule->day_of_week)
            ->get();
        $scheduleStart = substr((string) $schedule->start_time, 0, 5);
        $scheduleEnd = substr((string) $schedule->end_time, 0, 5);
        $unavailableOverlap = $windows->contains(fn ($window): bool => $window->availability_type === 'unavailable'
            && substr((string) $window->start_time, 0, 5) < $scheduleEnd
            && substr((string) $window->end_time, 0, 5) > $scheduleStart);

        if ($unavailableOverlap) {
            throw ValidationException::withMessages([
                'start_time' => 'The schedule overlaps an unavailable window for an assigned professor.',
            ]);
        }

        $availableWindows = $windows->filter(fn ($window): bool => in_array($window->availability_type, ['available', 'preferred'], true));
        if ($availableWindows->isNotEmpty() && ! $availableWindows->contains(fn ($window): bool => substr((string) $window->start_time, 0, 5) <= $scheduleStart
            && substr((string) $window->end_time, 0, 5) >= $scheduleEnd)) {
            throw ValidationException::withMessages([
                'start_time' => 'The schedule must fit inside an available or preferred window for every assigned professor.',
            ]);
        }
    }

    private function assertRoomFitsOffering(Room $room, SubjectOffering $offering): void
    {
        if ($room->status !== 'available') {
            throw ValidationException::withMessages(['room_id' => 'Only available rooms can be allocated.']);
        }
        if ($offering->expected_students !== null && $room->capacity < $offering->expected_students) {
            throw ValidationException::withMessages([
                'room_id' => 'The selected room capacity is below the offering’s expected enrollment.',
            ]);
        }
    }

    private function sharedProfessor(ClassSchedule $schedule, Collection $professorIds): ?int
    {
        $assignment = $schedule->scheduleAssignments()
            ->where('schedule_assignments.status', 'active')
            ->whereHas('facultyAssignment', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereIn('professor_id', $professorIds))
            ->with('facultyAssignment:id,professor_id')
            ->first();

        return $assignment?->facultyAssignment?->professor_id;
    }

    private function conflictPayload(
        ClassSchedule $other,
        string $type,
        ?int $roomId = null,
        ?int $professorId = null,
        ?int $sectionId = null,
    ): array {
        return [
            'schedule_id' => $other->id,
            'conflict_type' => $type,
            'room_id' => $roomId,
            'professor_id' => $professorId,
            'section_id' => $sectionId,
            'message' => match ($type) {
                'room' => 'The room overlaps another published schedule.',
                'faculty' => 'An instructor overlaps another published schedule.',
                default => 'The section overlaps another published schedule.',
            },
        ];
    }

    private function recordConflict(ClassSchedule $schedule, array $conflict): ScheduleConflict
    {
        $firstId = min($schedule->id, $conflict['schedule_id']);
        $secondId = max($schedule->id, $conflict['schedule_id']);
        $termId = $schedule->subjectOffering->academic_term_id;
        $identity = [
            'academic_term_id' => $termId,
            'class_schedule_id' => $firstId,
            'conflicting_class_schedule_id' => $secondId,
            'conflict_type' => $conflict['conflict_type'],
        ];
        $record = ScheduleConflict::query()->firstOrNew($identity);
        $sameWaivedResource = $record->exists
            && $record->status === 'waived'
            && self::sameIdentifier($record->room_id, $conflict['room_id'])
            && self::sameIdentifier($record->professor_id, $conflict['professor_id'])
            && self::sameIdentifier($record->section_id, $conflict['section_id']);
        if (! $sameWaivedResource) {
            $record->fill([
                'room_id' => $conflict['room_id'],
                'professor_id' => $conflict['professor_id'],
                'section_id' => $conflict['section_id'],
                'status' => 'open',
                'detected_at' => now(),
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'resolution_notes' => null,
            ]);
            $record->save();
        }

        return $record;
    }

    private static function sameIdentifier(?int $left, ?int $right): bool
    {
        return $left === null ? $right === null : $right !== null && (int) $left === (int) $right;
    }
}
