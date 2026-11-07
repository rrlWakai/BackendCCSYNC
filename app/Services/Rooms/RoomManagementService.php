<?php

namespace App\Services\Rooms;

use App\Models\Room;
use App\Models\RoomAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoomManagementService
{
    public function create(array $attributes): Room
    {
        return DB::transaction(fn (): Room => Room::create([
            ...$attributes,
            'status' => $attributes['status'] ?? 'available',
        ]), attempts: 3);
    }

    public function update(Room $room, array $attributes): Room
    {
        return DB::transaction(function () use ($room, $attributes): Room {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            $this->assertAllocationsRemainValid($lockedRoom, $attributes);
            $lockedRoom->update($attributes);

            return $lockedRoom->fresh();
        }, attempts: 3);
    }

    public function changeStatus(Room $room, string $status): Room
    {
        return $this->update($room, ['status' => $status]);
    }

    private function assertAllocationsRemainValid(Room $room, array $attributes): void
    {
        $nextStatus = $attributes['status'] ?? $room->status;

        if ($room->status === 'available' && $nextStatus !== 'available') {
            $hasActiveAllocations = $room->roomAllocations()
                ->where('status', 'active')
                ->exists();

            if ($hasActiveAllocations) {
                throw ValidationException::withMessages([
                    'status' => 'This room has active schedule allocations. Release or cancel them before changing its availability.',
                ]);
            }
        }

        if (! array_key_exists('capacity', $attributes)) {
            return;
        }

        $nextCapacity = (int) $attributes['capacity'];
        $capacityInvalidated = $room->roomAllocations()
            ->where('status', 'active')
            ->whereHas('classSchedule', fn ($query) => $query->where('status', 'published'))
            ->with('classSchedule.subjectOffering:id,expected_students')
            ->get()
            ->map(fn (RoomAllocation $allocation): ?int => $allocation->classSchedule?->subjectOffering?->expected_students)
            ->filter(fn (?int $expectedStudents): bool => $expectedStudents !== null)
            ->contains(fn (int $expectedStudents): bool => $expectedStudents > $nextCapacity);

        if ($capacityInvalidated) {
            throw ValidationException::withMessages([
                'capacity' => 'Capacity cannot be reduced below the expected enrollment of a published schedule using this room.',
            ]);
        }
    }
}
