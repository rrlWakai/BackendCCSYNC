<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\IndexRoomRequest;
use App\Http\Requests\Rooms\StoreRoomRequest;
use App\Http\Requests\Rooms\UpdateRoomRequest;
use App\Http\Requests\Rooms\UpdateRoomStatusRequest;
use App\Models\Room;
use App\Services\Rooms\RoomManagementService;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class RoomController extends Controller
{
    public function __construct(private readonly RoomManagementService $rooms) {}

    public function index(IndexRoomRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Room::class);
        $result = AcademicDirectory::paginate(
            Room::query()->withCount([
                'roomAllocations as active_allocations_count' => fn (Builder $query) => $query
                    ->where('status', 'active'),
                'roomAllocations as published_allocations_count' => fn (Builder $query) => $query
                    ->where('status', 'active')
                    ->whereHas('classSchedule', fn (Builder $schedule) => $schedule
                        ->where('status', 'published')),
            ]),
            $request,
            ['code', 'name', 'building'],
            [
                'status' => 'status',
                'room_type' => 'room_type',
            ],
        );

        return ApiResponse::success([
            'rooms' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Rooms loaded.');
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $room = $this->rooms->create($request->validated());

        return ApiResponse::success(['room' => $room], 'Room created.', 201);
    }

    public function show(Room $room): JsonResponse
    {
        Gate::authorize('view', $room);
        $room->loadCount([
            'roomAllocations as active_allocations_count' => fn (Builder $query) => $query
                ->where('status', 'active'),
            'roomAllocations as published_allocations_count' => fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereHas('classSchedule', fn (Builder $schedule) => $schedule
                    ->where('status', 'published')),
        ]);
        $room->load([
            'roomAllocations' => fn ($query) => $query
                ->where('status', 'active')
                ->with([
                    'classSchedule:id,subject_offering_id,day_of_week,start_time,end_time,status',
                    'classSchedule.subjectOffering:id,subject_id,section_id',
                    'classSchedule.subjectOffering.subject:id,code,title',
                    'classSchedule.subjectOffering.section:id,code,name',
                ])
                ->orderBy('id'),
        ]);

        return ApiResponse::success(['room' => $room], 'Room loaded.');
    }

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        $room = $this->rooms->update($room, $request->validated());

        return ApiResponse::success(['room' => $room], 'Room updated.');
    }

    public function updateStatus(UpdateRoomStatusRequest $request, Room $room): JsonResponse
    {
        $room = $this->rooms->changeStatus($room, $request->validated('status'));

        return ApiResponse::success(['room' => $room], 'Room status updated.');
    }
}
