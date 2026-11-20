<?php

namespace App\Services\Rooms;

use App\Models\ClassSchedule;
use App\Models\Professor;
use App\Models\Room;
use App\Models\RoomAllocation;
use App\Models\RoomOccupancy;
use App\Models\RoomOccupancyEvent;
use App\Models\RoomQrIdentity;
use App\Models\ScheduleAssignment;
use App\Models\User;
use App\Support\AttendanceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoomMonitoringService
{
    private const QR_PREFIX = 'ccsync-room-v1:';

    public function qrIdentity(Room $room): ?array
    {
        $identity = $room->qrIdentities()
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        return $identity === null ? null : $this->presentQrIdentity($identity);
    }

    public function qrHistory(Room $room): array
    {
        return $room->qrIdentities()
            ->with(['createdBy:id,name', 'revokedBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (RoomQrIdentity $identity): array => [
                'id' => $identity->id,
                'room_id' => $identity->room_id,
                'created_at' => $identity->created_at?->toIso8601String(),
                'revoked_at' => $identity->revoked_at?->toIso8601String(),
                'revocation_reason' => $identity->revocation_reason,
                'created_by' => $identity->createdBy?->name,
                'revoked_by' => $identity->revokedBy?->name,
            ])
            ->all();
    }

    public function regenerateQrIdentity(Room $room, User $actor): array
    {
        return DB::transaction(function () use ($room, $actor): array {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            $now = CarbonImmutable::now();

            RoomQrIdentity::query()
                ->where('room_id', $lockedRoom->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(function (RoomQrIdentity $identity) use ($actor, $now): void {
                    $identity->update([
                        'revoked_at' => $now,
                        'revoked_by_user_id' => $actor->id,
                        'revocation_reason' => 'Replaced by an authorized QR regeneration.',
                    ]);
                });

            $identity = RoomQrIdentity::create([
                'room_id' => $lockedRoom->id,
                'identifier' => bin2hex(random_bytes(32)),
                'created_by_user_id' => $actor->id,
            ]);

            return $this->presentQrIdentity($identity);
        }, attempts: 3);
    }

    public function revokeQrIdentity(Room $room, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($room, $actor, $reason): array {
            Room::query()->lockForUpdate()->findOrFail($room->id);
            $identity = RoomQrIdentity::query()
                ->where('room_id', $room->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                throw ValidationException::withMessages([
                    'room' => 'This room does not have an active QR identity.',
                ]);
            }

            $identity->update([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
            ]);

            return $this->presentQrIdentity($identity->fresh());
        }, attempts: 3);
    }

    public function previewCheckIn(string $identifier, User $actor): array
    {
        $identity = $this->activeIdentity($identifier);
        $professor = $this->activeProfessor($actor);
        $now = CarbonImmutable::now(AttendanceClock::timezone());
        $candidate = $this->scheduledAssignment($professor, $now);
        $actualRoom = Room::query()->findOrFail($identity->room_id);
        $scheduledRoom = $candidate['allocation']->room;
        $activeRoomOccupancy = $this->activeOccupancyForRoom($actualRoom->id, $now);
        $activeProfessorOccupancy = $this->activeOccupancyForProfessor($professor->id, $now);
        $sameClassOccupancy = $activeRoomOccupancy?->schedule_assignment_id === $candidate['assignment']->id;
        $reservation = $this->activeReservationForRoom(
            $actualRoom->id,
            $now,
            $candidate['schedule']->id,
        );

        return [
            'room' => $this->presentRoom($actualRoom),
            'schedule' => $this->presentSchedule($candidate),
            'scheduled_room' => $this->presentRoom($scheduledRoom),
            'requires_mismatch_override' => $scheduledRoom->id !== $actualRoom->id,
            'room_available' => $actualRoom->status === 'available'
                && ($activeRoomOccupancy === null || $sameClassOccupancy)
                && $reservation === null,
            'room_reserved_for_another_class' => $reservation !== null,
            'current_room_occupancy' => $activeRoomOccupancy === null
                ? null
                : $this->presentOccupancy($activeRoomOccupancy),
            'professor_has_other_active_occupancy' => $activeProfessorOccupancy !== null
                && $activeProfessorOccupancy->schedule_assignment_id !== $candidate['assignment']->id,
            'early_check_in_minutes' => $this->earlyCheckInMinutes(),
        ];
    }

    public function checkIn(string $identifier, User $actor, ?string $mismatchReason): array
    {
        $initialIdentity = $this->activeIdentity($identifier);
        $professor = $this->activeProfessor($actor);
        $initialNow = CarbonImmutable::now(AttendanceClock::timezone());
        $initialCandidate = $this->scheduledAssignment($professor, $initialNow);

        return DB::transaction(function () use (
            $identifier,
            $actor,
            $mismatchReason,
            $professor,
            $initialIdentity,
            $initialCandidate,
        ): array {
            $schedule = ClassSchedule::query()
                ->lockForUpdate()
                ->findOrFail($initialCandidate['assignment']->class_schedule_id);
            $assignment = ScheduleAssignment::query()
                ->whereKey($initialCandidate['assignment']->id)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = RoomQrIdentity::query()
                ->whereKey($initialIdentity->id)
                ->lockForUpdate()
                ->first();

            if ($identity === null || $identity->revoked_at !== null
                || $identity->identifier !== $identifier
                || $identity->room_id !== $initialIdentity->room_id) {
                throw ValidationException::withMessages([
                    'qr_identifier' => 'This room QR code has been revoked or is invalid.',
                ]);
            }

            $candidateNow = CarbonImmutable::now(AttendanceClock::timezone());
            $candidate = $this->scheduledAssignment($professor, $candidateNow);
            if ($candidate['assignment']->id !== $assignment->id
                || $candidate['schedule']->id !== $schedule->id) {
                throw ValidationException::withMessages([
                    'schedule' => 'The professor schedule changed while the room scan was being validated. Scan again.',
                ]);
            }

            $scheduledRoom = $candidate['allocation']->room;
            $actualRoomId = $identity->room_id;
            $roomIds = collect([$actualRoomId, $scheduledRoom->id])->unique()->sort()->values();
            $lockedRooms = Room::query()
                ->whereIn('id', $roomIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $actualRoom = $lockedRooms->get($actualRoomId);
            $scheduledRoom = $lockedRooms->get($scheduledRoom->id);

            if ($actualRoom === null || $scheduledRoom === null) {
                throw ValidationException::withMessages([
                    'room' => 'The scanned or scheduled room is no longer available.',
                ]);
            }

            Professor::query()->lockForUpdate()->findOrFail($professor->id);

            $now = CarbonImmutable::now(AttendanceClock::timezone());
            $this->closeExpiredFor($now, $roomIds->all(), $professor->id);

            $existing = RoomOccupancy::query()
                ->where('schedule_assignment_id', $assignment->id)
                ->whereDate('class_date', $candidate['date']->toDateString())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [
                    'occupancy' => $this->presentOccupancy($existing),
                    'already_checked_in' => true,
                ];
            }

            if ($actualRoom->status !== 'available') {
                throw ValidationException::withMessages([
                    'room' => 'The scanned room is not available for occupancy.',
                ]);
            }

            $activeProfessorOccupancy = $this->activeOccupancyForProfessor($professor->id, $now, true);
            if ($activeProfessorOccupancy !== null) {
                throw ValidationException::withMessages([
                    'schedule' => 'You already have an active room occupancy for another scheduled class.',
                ]);
            }

            $activeRoomOccupancy = $this->activeOccupancyForRoom($actualRoom->id, $now, true);
            if ($activeRoomOccupancy !== null) {
                throw ValidationException::withMessages([
                    'room' => 'This room already has an active occupancy.',
                ]);
            }

            if ($this->activeReservationForRoom($actualRoom->id, $now, $schedule->id) !== null) {
                throw ValidationException::withMessages([
                    'room' => 'This room is reserved for another published class during the current time window.',
                ]);
            }

            $mismatch = $actualRoom->id !== $scheduledRoom->id;
            $reason = $mismatch ? trim((string) $mismatchReason) : null;
            if ($mismatch && ($reason === '' || mb_strlen($reason) < 10)) {
                throw ValidationException::withMessages([
                    'mismatch_reason' => 'Provide a meaningful reason of at least 10 characters to check in to a different room.',
                ]);
            }

            $occupancy = RoomOccupancy::create([
                'room_id' => $actualRoom->id,
                'scheduled_room_id' => $scheduledRoom->id,
                'professor_id' => $professor->id,
                'class_schedule_id' => $schedule->id,
                'schedule_assignment_id' => $assignment->id,
                'room_allocation_id' => $candidate['allocation']->id,
                'room_qr_identity_id' => $identity->id,
                'checked_in_by_user_id' => $actor->id,
                'class_date' => $candidate['date']->toDateString(),
                'scheduled_start_at' => $candidate['starts_at']->setTimezone('UTC'),
                'scheduled_end_at' => $candidate['ends_at']->setTimezone('UTC'),
                'checked_in_at' => $now->setTimezone('UTC'),
                'mismatch_reason' => $reason,
                'discrepancy_status' => $mismatch ? 'open' : 'none',
            ]);

            $this->recordEvent($occupancy, 'checked_in', $actor, [
                'room_id' => $actualRoom->id,
                'scheduled_room_id' => $scheduledRoom->id,
                'mismatch_reason' => $reason,
            ], $now);

            return [
                'occupancy' => $this->presentOccupancy($occupancy->fresh()),
                'already_checked_in' => false,
            ];
        }, attempts: 3);
    }

    /** Return the authenticated professor's own room usage history. */
    public function professorHistory(User $actor, array $filters = []): array
    {
        $professor = $this->activeProfessor($actor);
        $query = RoomOccupancy::query()->with($this->occupancyRelations())
            ->where('professor_id', $professor->id)
            ->orderByDesc('class_date')->orderByDesc('checked_in_at');
        if (! empty($filters['date'])) $query->whereDate('class_date', $filters['date']);
        $paginator = $query->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
        return [
            'occupancies' => collect($paginator->items())->map(fn (RoomOccupancy $item): array => $this->presentOccupancy($item))->values(),
            'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ];
    }

    public function endProfessorUsage(User $actor): array
    {
        $professor = $this->activeProfessor($actor);
        return DB::transaction(function () use ($professor, $actor): array {
            $now = CarbonImmutable::now(AttendanceClock::timezone());
            $occupancy = RoomOccupancy::query()->where('professor_id', $professor->id)
                ->whereNull('checked_out_at')->where('scheduled_end_at', '>', $now->setTimezone('UTC'))
                ->latest('checked_in_at')->lockForUpdate()->first();
            if ($occupancy === null) {
                throw ValidationException::withMessages(['occupancy' => 'You do not have an active room usage session.']);
            }
            $this->closeOccupancy($occupancy, $now, 'professor', $actor);
            return ['occupancy' => $this->presentOccupancy($occupancy->fresh())];
        }, attempts: 3);
    }

    public function availability(array $filters): array
    {
        $timezone = AttendanceClock::timezone();
        $date = CarbonImmutable::parse($filters['date'] ?? CarbonImmutable::now($timezone)->toDateString(), $timezone);
        $now = CarbonImmutable::now($timezone);
        $query = Room::query()
            ->withCount([
                'roomAllocations as active_allocations_count' => fn (Builder $builder) => $builder
                    ->where('status', 'active'),
                'roomAllocations as published_allocations_count' => fn (Builder $builder) => $builder
                    ->where('status', 'active')
                    ->whereHas('classSchedule', fn (Builder $schedule) => $schedule
                        ->where('status', 'published')),
            ])
            ->orderBy('building')
            ->orderBy('code');

        if (! empty($filters['building'])) {
            $query->where('building', $filters['building']);
        }
        if (! empty($filters['room_type'])) {
            $query->where('room_type', $filters['room_type']);
        }
        if (! empty($filters['room_status'])) {
            $query->where('status', $filters['room_status']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('building', 'like', '%'.$search.'%');
            });
        }

        $rooms = $query->get();
        $allocations = RoomAllocation::query()
            ->with([
                'room:id,code,name,building,floor,status',
                'classSchedule:id,subject_offering_id,day_of_week,start_time,end_time,status',
                'classSchedule.subjectOffering:id,academic_term_id,section_id,subject_id,status',
                'classSchedule.subjectOffering.subject:id,code,title',
                'classSchedule.subjectOffering.section:id,code,name',
                'classSchedule.subjectOffering.academicTerm:id,starts_on,ends_on,status',
                'classSchedule.scheduleAssignments' => fn (Builder $builder) => $builder
                    ->where('status', 'active')
                    ->with([
                        'facultyAssignment:id,professor_id,status',
                        'facultyAssignment.professor:id,user_id,status',
                        'facultyAssignment.professor.user:id,name,status',
                    ]),
            ])
            ->whereIn('room_id', $rooms->modelKeys())
            ->where('status', 'active')
            ->whereHas('classSchedule', fn (Builder $builder) => $builder
                ->where('status', 'published')
                ->where('modality', '!=', 'online')
                ->where('day_of_week', $date->dayOfWeekIso)
                ->whereHas('subjectOffering', fn (Builder $offering) => $offering
                    ->where('status', 'open')
                    ->whereHas('academicTerm', fn (Builder $term) => $term
                        ->where('status', 'active')
                        ->whereDate('starts_on', '<=', $date->toDateString())
                        ->whereDate('ends_on', '>=', $date->toDateString()))))
            ->get()
            ->groupBy('room_id');

        $occupancyQuery = RoomOccupancy::query()
            ->with([
                'room:id,code,name,building',
                'scheduledRoom:id,code,name,building',
                'professor.user:id,name',
                'classSchedule.subjectOffering.subject:id,code,title',
                'classSchedule.subjectOffering.section:id,code,name',
            ])
            ->whereIn('room_id', $rooms->modelKeys());
        if ($date->isSameDay($now)) {
            $occupancyQuery->where(function (Builder $builder) use ($now): void {
                $builder->where(function (Builder $active) use ($now): void {
                    $active->whereDate('class_date', $now->toDateString())
                        ->whereNull('checked_out_at')
                        ->where('scheduled_end_at', '>', $now->setTimezone('UTC'))
                        ->whereHas('classSchedule', fn (Builder $schedule) => $schedule
                            ->where('status', '!=', 'cancelled'));
                })->orWhere('discrepancy_status', 'open');
            });
        } elseif ($date->gt($now)) {
            $occupancyQuery->where('discrepancy_status', 'open');
        } else {
            $occupancyQuery->where(function (Builder $builder) use ($date): void {
                $builder->whereDate('class_date', $date->toDateString())
                    ->orWhere('discrepancy_status', 'open');
            });
        }
        $occupancies = $occupancyQuery->get()->groupBy('room_id');

        $items = $rooms->map(function (Room $room) use ($allocations, $occupancies, $date, $now): array {
            $roomAllocations = $allocations->get($room->id, collect());
            $localClock = $now->format('H:i:s');
            $scheduled = $roomAllocations
                ->filter(function (RoomAllocation $allocation) use ($localClock, $date): bool {
                    $schedule = $allocation->classSchedule;
                    $term = $schedule?->subjectOffering?->academicTerm;

                    return $schedule?->status === 'published'
                        && $allocation->status === 'active'
                        && $term?->status === 'active'
                        && $term->starts_on->lte($date)
                        && $term->ends_on->gte($date);
                })
                ->sortBy(fn (RoomAllocation $allocation): string => (string) $allocation->classSchedule->start_time);

            $currentSchedule = $scheduled->first(function (RoomAllocation $allocation) use ($localClock): bool {
                return (string) $allocation->classSchedule->start_time <= $localClock
                    && (string) $allocation->classSchedule->end_time > $localClock;
            });
            $nextSchedule = $currentSchedule ?? $scheduled->first(
                fn (RoomAllocation $allocation): bool => (string) $allocation->classSchedule->start_time > $localClock,
            );
            $roomOccupancies = $occupancies->get($room->id, collect());
            $occupancy = $roomOccupancies->firstWhere('discrepancy_status', 'open')
                ?? $roomOccupancies->first();
            $status = $roomOccupancies->contains(
                fn (RoomOccupancy $record): bool => $record->discrepancy_status === 'open',
            )
                ? 'discrepancy'
                : ($occupancy !== null ? 'occupied' : ($currentSchedule !== null ? 'reserved' : 'available'));

            return [
                ...$this->presentRoom($room),
                'availability_status' => $status,
                'scheduled' => $nextSchedule === null ? null : $this->presentAllocation($nextSchedule),
                'occupancy' => $occupancy === null ? null : $this->presentOccupancy($occupancy),
            ];
        });

        if (! empty($filters['availability_status'])) {
            $items = $items->where('availability_status', $filters['availability_status'])->values();
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        $total = $items->count();

        return [
            'rooms' => $items->forPage($page, $perPage)->values(),
            'date' => $date->toDateString(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function roomHistory(Room $room, array $filters): array
    {
        $query = RoomOccupancy::query()
            ->with($this->occupancyRelations())
            ->where(function (Builder $builder) use ($room): void {
                $builder->where('room_id', $room->id)
                    ->orWhere('scheduled_room_id', $room->id);
            })
            ->orderByDesc('class_date')
            ->orderByDesc('checked_in_at');

        if (! empty($filters['date'])) {
            $query->whereDate('class_date', $filters['date']);
        }
        if (! empty($filters['discrepancy_status'])) {
            $query->where('discrepancy_status', $filters['discrepancy_status']);
        }

        $paginator = $query->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));

        return [
            'occupancies' => collect($paginator->items())->map(fn (RoomOccupancy $occupancy): array => $this->presentOccupancy($occupancy))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function openDiscrepancies(array $filters): array
    {
        $query = RoomOccupancy::query()
            ->with($this->occupancyRelations())
            ->where('discrepancy_status', 'open')
            ->orderByDesc('class_date')
            ->orderByDesc('checked_in_at');

        if (! empty($filters['building'])) {
            $query->whereHas('room', fn (Builder $builder) => $builder->where('building', $filters['building']));
        }
        if (! empty($filters['date'])) {
            $query->whereDate('class_date', $filters['date']);
        }

        $paginator = $query->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));

        return [
            'occupancies' => collect($paginator->items())->map(fn (RoomOccupancy $occupancy): array => $this->presentOccupancy($occupancy))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function resolveDiscrepancy(RoomOccupancy $occupancy, User $actor, string $notes): RoomOccupancy
    {
        return DB::transaction(function () use ($occupancy, $actor, $notes): RoomOccupancy {
            $locked = RoomOccupancy::query()->lockForUpdate()->findOrFail($occupancy->id);
            if ($locked->discrepancy_status !== 'open') {
                throw ValidationException::withMessages([
                    'occupancy' => 'Only open room discrepancies can be resolved.',
                ]);
            }

            $now = CarbonImmutable::now();
            $locked->update([
                'discrepancy_status' => 'resolved',
                'discrepancy_resolved_at' => $now,
                'discrepancy_resolved_by_user_id' => $actor->id,
                'discrepancy_resolution_notes' => $notes,
            ]);
            $this->recordEvent($locked, 'discrepancy_resolved', $actor, [
                'resolution_notes' => $notes,
            ], $now);

            return $locked->fresh($this->occupancyRelations());
        }, attempts: 3);
    }

    public function presentOccupancyForResponse(RoomOccupancy $occupancy): array
    {
        return $this->presentOccupancy($occupancy);
    }

    public function processExpired(int $limit = 200): int
    {
        $processed = 0;
        $now = CarbonImmutable::now(AttendanceClock::timezone())->setTimezone('UTC');

        do {
            $ids = RoomOccupancy::query()
                ->whereNull('checked_out_at')
                ->where('scheduled_end_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            foreach ($ids as $id) {
                $closed = DB::transaction(function () use ($id): bool {
                    $occupancy = RoomOccupancy::query()->lockForUpdate()->find($id);
                    if ($occupancy === null || $occupancy->checked_out_at !== null) {
                        return false;
                    }

                    $this->closeOccupancy(
                        $occupancy,
                        $occupancy->scheduled_end_at,
                        'scheduled',
                        null,
                    );

                    return true;
                }, attempts: 3);
                $processed += $closed ? 1 : 0;
            }
        } while ($ids->isNotEmpty());

        return $processed;
    }

    public function closeForCancelledSchedule(ClassSchedule $schedule, User $actor): int
    {
        $now = CarbonImmutable::now(AttendanceClock::timezone());
        $occupancies = RoomOccupancy::query()
            ->where('class_schedule_id', $schedule->id)
            ->whereNull('checked_out_at')
            ->lockForUpdate()
            ->get();

        foreach ($occupancies as $occupancy) {
            $checkoutAt = $occupancy->scheduled_end_at->lt($now)
                ? $occupancy->scheduled_end_at
                : $now;
            $this->closeOccupancy($occupancy, $checkoutAt, 'schedule_cancelled', $actor);
        }

        return $occupancies->count();
    }

    private function activeIdentity(string $identifier): RoomQrIdentity
    {
        $identity = RoomQrIdentity::query()
            ->where('identifier', $identifier)
            ->whereNull('revoked_at')
            ->first();

        if ($identity === null) {
            throw ValidationException::withMessages([
                'qr_identifier' => 'This room QR code is invalid or has been revoked.',
            ]);
        }

        return $identity;
    }

    private function activeProfessor(User $actor): Professor
    {
        $professor = Professor::query()
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();

        if ($professor === null || ! $actor->isActive()) {
            throw ValidationException::withMessages([
                'professor' => 'An active professor account is required to check in to a room.',
            ]);
        }

        return $professor;
    }

    /**
     * @return array{assignment: ScheduleAssignment, schedule: ClassSchedule, allocation: RoomAllocation, date: CarbonImmutable, starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private function scheduledAssignment(Professor $professor, CarbonImmutable $now): array
    {
        $date = $now->startOfDay();
        $assignments = ScheduleAssignment::query()
            ->with([
                'facultyAssignment.professor.user',
                'classSchedule.subjectOffering.subject',
                'classSchedule.subjectOffering.section',
                'classSchedule.subjectOffering.academicTerm',
                'classSchedule.roomAllocations' => fn (Builder $builder) => $builder
                    ->where('status', 'active')
                    ->with('room'),
            ])
            ->where('status', 'active')
            ->whereHas('facultyAssignment', fn (Builder $builder) => $builder
                ->where('status', 'active')
                ->where('professor_id', $professor->id))
            ->whereHas('classSchedule', fn (Builder $builder) => $builder
                ->where('status', 'published')
                ->where('modality', '!=', 'online')
                ->where('day_of_week', $now->dayOfWeekIso)
                ->whereHas('subjectOffering', fn (Builder $offering) => $offering
                    ->where('status', 'open')
                    ->whereHas('academicTerm', fn (Builder $term) => $term
                        ->where('status', 'active')
                        ->whereDate('starts_on', '<=', $date->toDateString())
                        ->whereDate('ends_on', '>=', $date->toDateString()))))
            ->get();

        $earlyMinutes = $this->earlyCheckInMinutes();
        $candidates = $assignments->map(function (ScheduleAssignment $assignment) use ($date, $now, $earlyMinutes): ?array {
            $schedule = $assignment->classSchedule;
            $allocations = $schedule->roomAllocations;
            if ($allocations->count() !== 1) {
                return null;
            }

            $startsAt = CarbonImmutable::parse($date->toDateString().' '.$schedule->start_time, AttendanceClock::timezone());
            $endsAt = CarbonImmutable::parse($date->toDateString().' '.$schedule->end_time, AttendanceClock::timezone());
            if ($now->lt($startsAt->subMinutes($earlyMinutes)) || $now->gte($endsAt)) {
                return null;
            }

            return [
                'assignment' => $assignment,
                'schedule' => $schedule,
                'allocation' => $allocations->first(),
                'date' => $date,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        })->filter()->values();

        if ($candidates->count() !== 1) {
            throw ValidationException::withMessages([
                'schedule' => $candidates->isEmpty()
                    ? 'No published room-based class is assigned to you within the permitted check-in window for this academic term.'
                    : 'More than one published class matches the current check-in window. Contact an administrator before checking in.',
            ]);
        }

        $candidate = $candidates->first();
        if ($candidate['allocation']->room === null) {
            throw ValidationException::withMessages([
                'schedule' => 'The scheduled class no longer has a valid room allocation.',
            ]);
        }

        return $candidate;
    }

    private function activeOccupancyForRoom(int $roomId, CarbonImmutable $now, bool $lock = false): ?RoomOccupancy
    {
        $query = RoomOccupancy::query()
            ->where('room_id', $roomId)
            ->whereNull('checked_out_at')
            ->where('scheduled_end_at', '>', $now->setTimezone('UTC'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activeOccupancyForProfessor(
        int $professorId,
        CarbonImmutable $now,
        bool $lock = false,
    ): ?RoomOccupancy {
        $query = RoomOccupancy::query()
            ->where('professor_id', $professorId)
            ->whereNull('checked_out_at')
            ->where('scheduled_end_at', '>', $now->setTimezone('UTC'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activeReservationForRoom(
        int $roomId,
        CarbonImmutable $now,
        int $excludedScheduleId,
    ): ?RoomAllocation {
        $date = $now->toDateString();
        $localTime = $now->format('H:i:s');

        return RoomAllocation::query()
            ->where('room_id', $roomId)
            ->where('status', 'active')
            ->whereHas('classSchedule', fn (Builder $builder) => $builder
                ->whereKeyNot($excludedScheduleId)
                ->where('status', 'published')
                ->where('modality', '!=', 'online')
                ->where('day_of_week', $now->dayOfWeekIso)
                ->where('start_time', '<=', $localTime)
                ->where('end_time', '>', $localTime)
                ->whereHas('subjectOffering', fn (Builder $offering) => $offering
                ->where('status', 'open')
                    ->whereHas('academicTerm', fn (Builder $term) => $term
                        ->where('status', 'active')
                        ->whereDate('starts_on', '<=', $date)
                        ->whereDate('ends_on', '>=', $date))))
            ->first();
    }

    private function closeExpired(CarbonImmutable $now, array $roomIds, int $professorId): void
    {
        $query = RoomOccupancy::query()
            ->whereNull('checked_out_at')
            ->where('scheduled_end_at', '<=', $now->setTimezone('UTC'))
            ->where(function (Builder $builder) use ($roomIds, $professorId): void {
                $builder->where('professor_id', $professorId)
                    ->orWhereIn('room_id', $roomIds);
            })
            ->lockForUpdate();

        foreach ($query->get() as $occupancy) {
            $this->closeOccupancy($occupancy, $occupancy->scheduled_end_at, 'scheduled', null);
        }
    }

    private function closeOccupancy(
        RoomOccupancy $occupancy,
        CarbonImmutable $checkoutAt,
        string $source,
        ?User $actor,
    ): void {
        if ($occupancy->checked_out_at !== null) {
            return;
        }

        $occupancy->update([
            'checked_out_at' => $checkoutAt->setTimezone('UTC'),
            'checkout_source' => $source,
        ]);
        $this->recordEvent($occupancy, 'checked_out', $actor, [
            'checkout_source' => $source,
            'checked_out_at' => $checkoutAt->toIso8601String(),
        ], $checkoutAt);
    }

    private function recordEvent(
        RoomOccupancy $occupancy,
        string $eventType,
        ?User $actor,
        array $details,
        CarbonImmutable $occurredAt,
    ): void {
        RoomOccupancyEvent::create([
            'room_occupancy_id' => $occupancy->id,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'details' => $details,
            'occurred_at' => $occurredAt->setTimezone('UTC'),
        ]);
    }

    private function earlyCheckInMinutes(): int
    {
        return max(0, (int) config('room_monitoring.early_check_in_minutes', 10));
    }

    private function presentQrIdentity(RoomQrIdentity $identity): array
    {
        return [
            'id' => $identity->id,
            'room_id' => $identity->room_id,
            'identifier' => $identity->identifier,
            'payload' => self::QR_PREFIX.$identity->identifier,
            'created_at' => $identity->created_at?->toIso8601String(),
            'revoked_at' => $identity->revoked_at?->toIso8601String(),
            'revocation_reason' => $identity->revocation_reason,
        ];
    }

    private function presentRoom(Room $room): array
    {
        return [
            'id' => $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'building' => $room->building,
            'floor' => $room->floor,
            'capacity' => $room->capacity,
            'room_type' => $room->room_type,
            'status' => $room->status,
            'active_allocations_count' => $room->active_allocations_count,
            'published_allocations_count' => $room->published_allocations_count,
        ];
    }

    private function presentSchedule(array $candidate): array
    {
        $schedule = $candidate['schedule'];
        $offering = $schedule->subjectOffering;
        $subject = $offering?->subject;
        $section = $offering?->section;
        $professor = $candidate['assignment']->facultyAssignment?->professor;

        return [
            'class_schedule_id' => $schedule->id,
            'schedule_assignment_id' => $candidate['assignment']->id,
            'subject' => $subject === null ? null : [
                'id' => $subject->id,
                'code' => $subject->code,
                'title' => $subject->title,
            ],
            'section' => $section === null ? null : [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
            ],
            'professor' => $professor?->user?->name,
            'class_date' => $candidate['date']->toDateString(),
            'starts_at' => $candidate['starts_at']->toIso8601String(),
            'ends_at' => $candidate['ends_at']->toIso8601String(),
        ];
    }

    private function presentAllocation(RoomAllocation $allocation): array
    {
        $assignment = $allocation->classSchedule?->scheduleAssignments
            ->first(fn (ScheduleAssignment $item): bool => $item->status === 'active'
                && $item->facultyAssignment?->status === 'active');
        $schedule = $allocation->classSchedule;
        $offering = $schedule?->subjectOffering;
        $subject = $offering?->subject;
        $section = $offering?->section;
        $professor = $assignment?->facultyAssignment?->professor;

        return [
            'class_schedule_id' => $schedule?->id,
            'subject' => $subject === null ? null : [
                'code' => $subject->code,
                'title' => $subject->title,
            ],
            'section' => $section === null ? null : [
                'code' => $section->code,
                'name' => $section->name,
            ],
            'professor' => $professor?->user?->name,
            'starts_at' => $schedule?->start_time,
            'ends_at' => $schedule?->end_time,
        ];
    }

    private function presentOccupancy(RoomOccupancy $occupancy): array
    {
        $occupancy->loadMissing($this->occupancyRelations());
        $local = fn ($value): ?string => $value?->copy()->setTimezone(AttendanceClock::timezone())->toIso8601String();
        $subject = $occupancy->classSchedule?->subjectOffering?->subject;
        $section = $occupancy->classSchedule?->subjectOffering?->section;
        $checkedOutAt = $occupancy->checked_out_at;
        $checkoutSource = $occupancy->checkout_source;
        if ($checkedOutAt === null
            && $occupancy->scheduled_end_at->lte(
                CarbonImmutable::now(AttendanceClock::timezone())->setTimezone('UTC'),
            )) {
            $checkedOutAt = $occupancy->scheduled_end_at;
            $checkoutSource = 'scheduled';
        }
        $durationSeconds = $checkedOutAt === null ? null : max(0, $occupancy->checked_in_at->diffInSeconds($checkedOutAt));

        return [
            'id' => $occupancy->id,
            'room' => $this->presentRoom($occupancy->room),
            'scheduled_room' => $this->presentRoom($occupancy->scheduledRoom),
            'professor' => [
                'id' => $occupancy->professor_id,
                'name' => $occupancy->professor?->user?->name,
            ],
            'class_schedule_id' => $occupancy->class_schedule_id,
            'schedule_assignment_id' => $occupancy->schedule_assignment_id,
            'subject' => $subject === null ? null : [
                'code' => $subject->code,
                'title' => $subject->title,
            ],
            'section' => $section === null ? null : [
                'code' => $section->code,
                'name' => $section->name,
            ],
            'class_date' => $occupancy->class_date?->toDateString(),
            'scheduled_start_at' => $local($occupancy->scheduled_start_at),
            'scheduled_end_at' => $local($occupancy->scheduled_end_at),
            'checked_in_at' => $local($occupancy->checked_in_at),
            'checked_out_at' => $local($checkedOutAt),
            'duration_seconds' => $durationSeconds,
            'checkout_source' => $checkoutSource,
            'mismatch_reason' => $occupancy->mismatch_reason,
            'discrepancy_status' => $occupancy->discrepancy_status,
            'discrepancy_resolved_at' => $local($occupancy->discrepancy_resolved_at),
            'discrepancy_resolved_by' => $occupancy->discrepancyResolvedBy?->name,
            'discrepancy_resolution_notes' => $occupancy->discrepancy_resolution_notes,
            'events' => $occupancy->relationLoaded('events')
                ? $occupancy->events->map(fn (RoomOccupancyEvent $event): array => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'actor' => $event->actor?->name,
                    'details' => $event->details,
                    'occurred_at' => $local($event->occurred_at),
                ])->values()
                : [],
        ];
    }

    private function occupancyRelations(): array
    {
        return [
            'room:id,code,name,building,floor,status',
            'scheduledRoom:id,code,name,building,floor,status',
            'professor.user:id,name',
            'classSchedule.subjectOffering.subject:id,code,title',
            'classSchedule.subjectOffering.section:id,code,name',
            'discrepancyResolvedBy:id,name',
            'events.actor:id,name',
        ];
    }
}
