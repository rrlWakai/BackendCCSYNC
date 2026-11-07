<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreYearLevelRequest;
use App\Http\Requests\Academic\UpdateYearLevelRequest;
use App\Models\YearLevel;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class YearLevelController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', YearLevel::class);
        $result = AcademicDirectory::paginate(YearLevel::query(), $request, ['name']);

        return ApiResponse::success([
            'year_levels' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Year levels loaded.');
    }

    public function store(StoreYearLevelRequest $request): JsonResponse
    {
        $yearLevel = YearLevel::create($request->validated());

        return ApiResponse::success(['year_level' => $yearLevel], 'Year level created.', 201);
    }

    public function show(YearLevel $year_level): JsonResponse
    {
        Gate::authorize('view', $year_level);

        return ApiResponse::success(['year_level' => $year_level], 'Year level loaded.');
    }

    public function update(UpdateYearLevelRequest $request, YearLevel $year_level): JsonResponse
    {
        $year_level->update($request->validated());

        return ApiResponse::success(['year_level' => $year_level->fresh()], 'Year level updated.');
    }
}
