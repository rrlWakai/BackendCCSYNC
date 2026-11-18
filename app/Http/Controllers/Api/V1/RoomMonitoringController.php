<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\ResolveRoomDiscrepancyRequest;
use App\Http\Requests\Rooms\RevokeRoomQrRequest;
use App\Http\Requests\Rooms\RoomAvailabilityRequest;
use App\Http\Requests\Rooms\RoomHistoryRequest;
use App\Http\Requests\Rooms\ScanRoomRequest;
use App\Models\Room;
use App\Models\RoomOccupancy;
use App\Models\User;
use App\Services\Rooms\RoomMonitoringService;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RoomMonitoringController extends Controller
{
    public function __construct(private readonly RoomMonitoringService $monitoring) {}

    public function availability(RoomAvailabilityRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Room::class);

        return ApiResponse::success(
            $this->monitoring->availability($request->validated()),
            'Room availability loaded.',
        );
    }

    public function qr(Room $room): JsonResponse
    {
        Gate::authorize('manageQr', $room);

        return ApiResponse::success([
            'qr_identity' => $this->monitoring->qrIdentity($room),
        ], 'Room QR identity loaded.');
    }

    public function regenerateQr(Request $request, Room $room): JsonResponse
    {
        Gate::authorize('manageQr', $room);

        return ApiResponse::success([
            'qr_identity' => $this->monitoring->regenerateQrIdentity($room, $this->actor($request)),
        ], 'Room QR code regenerated.');
    }

    public function qrHistory(Room $room): JsonResponse
    {
        Gate::authorize('manageQr', $room);

        return ApiResponse::success([
            'qr_identities' => $this->monitoring->qrHistory($room),
        ], 'Room QR history loaded.');
    }

    public function revokeQr(RevokeRoomQrRequest $request, Room $room): JsonResponse
    {
        Gate::authorize('manageQr', $room);

        return ApiResponse::success([
            'qr_identity' => $this->monitoring->revokeQrIdentity(
                $room,
                $this->actor($request),
                $request->validated('reason'),
            ),
        ], 'Room QR code revoked.');
    }

    public function history(RoomHistoryRequest $request, Room $room): JsonResponse
    {
        Gate::authorize('view', $room);

        return ApiResponse::success(
            $this->monitoring->roomHistory($room, $request->validated()),
            'Room occupancy history loaded.',
        );
    }

    public function discrepancies(RoomHistoryRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Room::class);

        return ApiResponse::success(
            $this->monitoring->openDiscrepancies($request->validated()),
            'Open room discrepancies loaded.',
        );
    }

    public function resolveDiscrepancy(
        ResolveRoomDiscrepancyRequest $request,
        RoomOccupancy $occupancy,
    ): JsonResponse {
        Gate::authorize('resolveDiscrepancy', Room::class);

        $resolved = $this->monitoring->resolveDiscrepancy(
            $occupancy,
            $this->actor($request),
            $request->validated('resolution_notes'),
        );

        return ApiResponse::success([
            'occupancy' => $this->monitoring->presentOccupancyForResponse($resolved),
        ], 'Room discrepancy resolved.');
    }

    public function preview(ScanRoomRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->monitoring->previewCheckIn(
                $request->validated('qr_identifier'),
                $this->actor($request),
            ),
            'Room QR and schedule validated.',
        );
    }

    public function checkIn(ScanRoomRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->monitoring->checkIn(
                $request->validated('qr_identifier'),
                $this->actor($request),
                $request->validated('mismatch_reason'),
            ),
            'Room check-in recorded.',
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
