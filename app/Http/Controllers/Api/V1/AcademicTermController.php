<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreAcademicTermRequest;
use App\Http\Requests\Academic\UpdateAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AcademicTermController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AcademicTerm::class);
        $result = AcademicDirectory::paginate(
            AcademicTerm::query(),
            $request,
            ['academic_year', 'term', 'name'],
            ['status' => 'status'],
        );

        return ApiResponse::success([
            'terms' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Academic terms loaded.');
    }

    public function store(StoreAcademicTermRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $term = DB::transaction(function () use ($attributes): AcademicTerm {
            if ($attributes['is_current'] ?? false) {
                AcademicTerm::query()->lockForUpdate()->get(['id']);
                AcademicTerm::query()->where('is_current', true)->update(['is_current' => false]);
            }

            return AcademicTerm::create($attributes);
        });

        return ApiResponse::success(['term' => $term], 'Academic term created.', 201);
    }

    public function show(AcademicTerm $term): JsonResponse
    {
        Gate::authorize('view', $term);

        return ApiResponse::success(['term' => $term], 'Academic term loaded.');
    }

    public function update(UpdateAcademicTermRequest $request, AcademicTerm $term): JsonResponse
    {
        $attributes = $request->validated();
        DB::transaction(function () use ($attributes, $term): void {
            AcademicTerm::query()->lockForUpdate()->get(['id']);
            if ($attributes['is_current'] ?? false) {
                AcademicTerm::query()
                    ->where('is_current', true)
                    ->whereKeyNot($term->getKey())
                    ->update(['is_current' => false]);
            }

            $term->update($attributes);
        });

        return ApiResponse::success(['term' => $term->fresh()], 'Academic term updated.');
    }
}
