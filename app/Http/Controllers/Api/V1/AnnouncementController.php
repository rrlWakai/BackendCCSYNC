<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\IndexAnnouncementFeedRequest;
use App\Http\Requests\Announcements\IndexAnnouncementHistoryRequest;
use App\Http\Requests\Announcements\IndexManagedAnnouncementsRequest;
use App\Http\Requests\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Announcements\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Services\Announcements\AnnouncementManagementService;
use App\Support\AnnouncementPresenter;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementManagementService $announcements) {}

    public function manage(IndexManagedAnnouncementsRequest $request): JsonResponse
    {
        Gate::authorize('manage', Announcement::class);

        return ApiResponse::success(
            $this->announcements->managePage($request->validated(), $request->user()),
            'Announcements loaded.',
        );
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcements->create($request->validated(), $request->user());

        return ApiResponse::success(
            ['announcement' => AnnouncementPresenter::announcement($announcement)],
            'Announcement created.',
            201,
        );
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $announcement = $this->announcements->update(
            $announcement,
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::success(
            ['announcement' => AnnouncementPresenter::announcement($announcement)],
            'Announcement updated.',
        );
    }

    public function publish(Announcement $announcement): JsonResponse
    {
        Gate::authorize('publish', $announcement);
        $announcement = $this->announcements->publish($announcement, request()->user());

        return ApiResponse::success(
            ['announcement' => AnnouncementPresenter::announcement($announcement)],
            'Announcement published.',
        );
    }

    public function archive(Announcement $announcement): JsonResponse
    {
        Gate::authorize('archive', $announcement);
        $announcement = $this->announcements->archive($announcement, request()->user());

        return ApiResponse::success(
            ['announcement' => AnnouncementPresenter::announcement($announcement)],
            'Announcement archived.',
        );
    }

    public function history(
        IndexAnnouncementHistoryRequest $request,
        Announcement $announcement,
    ): JsonResponse {
        Gate::authorize('viewHistory', $announcement);

        return ApiResponse::success(
            $this->announcements->historyPage($announcement, $request->validated()),
            'Announcement history loaded.',
        );
    }

    public function index(IndexAnnouncementFeedRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Announcement::class);

        return ApiResponse::success(
            $this->announcements->feedPage($request->validated(), $request->user()),
            'Announcements loaded.',
        );
    }

    public function show(Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        return ApiResponse::success(
            ['announcement' => AnnouncementPresenter::announcement($announcement)],
            'Announcement loaded.',
        );
    }
}
