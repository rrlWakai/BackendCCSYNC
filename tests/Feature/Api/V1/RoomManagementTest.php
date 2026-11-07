<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Room;
use App\Models\RoomAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_room_directory_is_searchable_filterable_and_paginated(): void
    {
        $manager = $this->makeUser(Role::ADMINISTRATOR);

        for ($index = 1; $index <= 12; $index++) {
            $this->makeRoom([
                'code' => sprintf('RM-%02d', $index),
                'name' => $index === 12 ? 'Annex Laboratory' : 'Room '.$index,
                'building' => $index === 12 ? 'Annex' : 'Main',
                'room_type' => $index === 12 ? 'laboratory' : 'lecture',
                'status' => $index % 2 === 0 ? 'available' : 'maintenance',
            ]);
        }

        $this->actingAs($manager)
            ->getJson('/api/v1/rooms?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 12)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonCount(2, 'data.rooms');

        $this->getJson('/api/v1/rooms?search=Annex')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.rooms.0.code', 'RM-12');

        $this->getJson('/api/v1/rooms?status=available&room_type=laboratory')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.rooms.0.code', 'RM-12');
    }

    public function test_administrators_and_department_personnel_can_create_rooms(): void
    {
        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $index => $role) {
            $this->actingAs($this->makeUser($role));

            $code = $index === 0 ? ' lab-101 ' : 'lab-102';

            $this->postJson('/api/v1/rooms', [
                'code' => $code,
                'name' => 'Computer Laboratory',
                'building' => 'Main',
                'floor' => '3',
                'capacity' => 45,
                'room_type' => 'laboratory',
                'status' => 'available',
            ])
                ->assertCreated()
                ->assertJsonPath('data.room.code', strtoupper(trim($code)))
                ->assertJsonPath('data.room.status', 'available');
        }

        $this->assertDatabaseHas('rooms', [
            'code' => 'LAB-101',
            'capacity' => 45,
            'room_type' => 'laboratory',
        ]);
    }

    public function test_professors_students_and_inactive_managers_cannot_access_room_management(): void
    {
        $room = $this->makeRoom();

        foreach ([Role::PROFESSOR, Role::STUDENT] as $role) {
            $this->actingAs($this->makeUser($role));

            $this->getJson('/api/v1/rooms')->assertForbidden();
            $this->getJson("/api/v1/rooms/{$room->id}")->assertForbidden();
            $this->postJson('/api/v1/rooms', [])->assertForbidden();
            $this->patchJson("/api/v1/rooms/{$room->id}", [])->assertForbidden();
            $this->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'unavailable'])
                ->assertForbidden();
        }

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR, ['status' => 'inactive']))
            ->getJson('/api/v1/rooms')
            ->assertForbidden();
    }

    public function test_room_creation_and_updates_enforce_schema_validation(): void
    {
        $existing = $this->makeRoom(['code' => 'ROOM-EXIST']);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/rooms', [
            'code' => 'ROOM-EXIST',
            'name' => 'Duplicate room',
            'capacity' => 30,
            'room_type' => 'lecture',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/rooms', [
            'code' => 'ROOM-ZERO',
            'name' => 'Zero capacity',
            'capacity' => 0,
            'room_type' => 'lecture',
        ])->assertUnprocessable()->assertJsonValidationErrors('capacity');

        $this->postJson('/api/v1/rooms', [
            'code' => 'ROOM-TYPE',
            'name' => 'Invalid type',
            'capacity' => 30,
            'room_type' => 'gym',
        ])->assertUnprocessable()->assertJsonValidationErrors('room_type');

        $this->postJson('/api/v1/rooms', [
            'code' => 'ROOM-STATUS',
            'name' => 'Invalid status',
            'capacity' => 30,
            'room_type' => 'lecture',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->patchJson("/api/v1/rooms/{$existing->id}", ['capacity' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');

        $this->patchJson("/api/v1/rooms/{$existing->id}", ['code' => 'room-exist'])
            ->assertOk()
            ->assertJsonPath('data.room.code', 'ROOM-EXIST');
    }

    public function test_room_details_and_status_changes_work_for_a_free_room(): void
    {
        $room = $this->makeRoom(['code' => 'FREE-101']);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->patchJson("/api/v1/rooms/{$room->id}", [
            'name' => 'Renamed Room',
            'building' => 'Annex',
            'floor' => '2',
            'capacity' => 55,
        ])
            ->assertOk()
            ->assertJsonPath('data.room.name', 'Renamed Room')
            ->assertJsonPath('data.room.capacity', 55);

        $this->getJson("/api/v1/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.room.active_allocations_count', 0)
            ->assertJsonCount(0, 'data.room.room_allocations');

        $this->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'unavailable'])
            ->assertOk()
            ->assertJsonPath('data.room.status', 'unavailable');

        $this->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.room.status', 'available');
    }

    public function test_room_availability_and_capacity_are_protected_while_a_published_schedule_uses_the_room(): void
    {
        $room = $this->makeRoom(['code' => 'PROTECTED-101', 'capacity' => 40]);
        $allocation = $this->makePublishedRoomAllocation($room, 40);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'unavailable'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/v1/rooms/{$room->id}", ['capacity' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'status' => 'available',
            'capacity' => 40,
        ]);
        $this->assertDatabaseHas('room_allocations', [
            'id' => $allocation->id,
            'status' => 'active',
        ]);

        $this->patchJson("/api/v1/rooms/{$room->id}", [
            'name' => 'Protected Room',
            'capacity' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.room.capacity', 50);

        $this->assertDatabaseHas('room_allocations', [
            'id' => $allocation->id,
            'status' => 'active',
        ]);
    }

    public function test_inactive_rooms_cannot_be_allocated_to_new_schedules(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $schedule = $this->makeClassSchedule($offering, ['status' => 'draft']);
        $room = $this->makeRoom(['status' => 'unavailable']);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson("/api/v1/schedules/{$schedule->id}/room-allocations", [
                'room_id' => $room->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->assertDatabaseMissing('room_allocations', [
            'class_schedule_id' => $schedule->id,
            'room_id' => $room->id,
        ]);
    }

    public function test_room_details_include_active_published_allocations(): void
    {
        $room = $this->makeRoom(['code' => 'DETAIL-101']);
        $allocation = $this->makePublishedRoomAllocation($room, 40);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson("/api/v1/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.room.active_allocations_count', 1)
            ->assertJsonPath('data.room.published_allocations_count', 1)
            ->assertJsonPath('data.room.room_allocations.0.id', $allocation->id)
            ->assertJsonPath('data.room.room_allocations.0.class_schedule.status', 'published');
    }

    private function makePublishedRoomAllocation(Room $room, int $expectedStudents): RoomAllocation
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject(), [
            'expected_students' => $expectedStudents,
        ]);
        $schedule = $this->makeClassSchedule($offering, [
            'status' => 'published',
            'modality' => 'face_to_face',
        ]);

        return RoomAllocation::create([
            'class_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'status' => 'active',
            'allocated_at' => now(),
        ]);
    }
}
