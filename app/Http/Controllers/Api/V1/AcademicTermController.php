<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreAcademicTermRequest;
use App\Http\Requests\Academic\UpdateAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Services\Academic\AcademicYearService;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AcademicTermController extends Controller
{
    public function __construct(private readonly AcademicYearService $academicYears) {}

    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AcademicTerm::class);
        $result = AcademicDirectory::paginate(
            AcademicTerm::query(),
            $request,
            ['academic_year', 'term', 'name'],
            ['status' => 'status', 'academic_year_id' => 'academic_year_id'],
        );

        return ApiResponse::success([
            'terms' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Academic terms loaded.');
    }

    public function store(StoreAcademicTermRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $attributes = $this->resolveAcademicYear($attributes);
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
        $attributes = $this->resolveAcademicYear($request->validated(), $term);
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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolveAcademicYear(array $attributes, ?AcademicTerm $term = null): array
    {
        if (! array_key_exists('academic_year_id', $attributes)
            && ! array_key_exists('academic_year', $attributes)
            && $term !== null) {
            return $attributes;
        }

        if (($attributes['academic_year_id'] ?? null) !== null) {
            $year = AcademicYear::query()->findOrFail($attributes['academic_year_id']);
        } else {
            $label = $attributes['academic_year'] ?? $term?->academic_year;
            $startsOn = $attributes['starts_on'] ?? $term?->starts_on?->toDateString() ?? now()->toDateString();
            $endsOn = $attributes['ends_on'] ?? $term?->ends_on?->toDateString() ?? now()->addYear()->toDateString();
            $status = $attributes['status'] ?? $term?->status ?? 'planned';
            $year = $this->academicYears->resolve((string) $label, $startsOn, $endsOn, $status);
        }

        $attributes['academic_year_id'] = $year->id;
        $attributes['academic_year'] = $year->label;

        return $attributes;
    }
}
