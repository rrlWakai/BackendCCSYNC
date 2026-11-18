<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\MajorSelectionPeriod;
use App\Models\YearLevel;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MajorSelectionPeriodController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', MajorSelectionPeriod::class);

        $query = MajorSelectionPeriod::query()->with([
            'academicYear:id,label,status',
            'program:id,code,name,status',
            'targetYearLevel:id,level,name',
        ]);

        foreach (['program_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->integer('academic_year_id'));
        }

        if ($request->filled('target_year_level_id')) {
            $query->where('target_year_level_id', $request->integer('target_year_level_id'));
        }

        $result = AcademicDirectory::paginate($query->orderByDesc('id'), $request, [], []);

        return ApiResponse::success([
            'major_selection_periods' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Major selection periods loaded.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', MajorSelectionPeriod::class);
        $attributes = $this->validatePayload($request);

        $period = MajorSelectionPeriod::create($attributes);

        return ApiResponse::success(
            ['major_selection_period' => $period->load(['academicYear', 'program', 'targetYearLevel'])],
            'Major selection period created.',
            201,
        );
    }

    public function show(MajorSelectionPeriod $period): JsonResponse
    {
        Gate::authorize('view', MajorSelectionPeriod::class);

        return ApiResponse::success([
            'major_selection_period' => $period->load(['academicYear', 'program', 'targetYearLevel']),
        ], 'Major selection period loaded.');
    }

    public function update(Request $request, MajorSelectionPeriod $period): JsonResponse
    {
        Gate::authorize('update', MajorSelectionPeriod::class);
        $attributes = $this->validatePayload($request, $period);

        $period->update($attributes);

        return ApiResponse::success(
            ['major_selection_period' => $period->fresh()->load(['academicYear', 'program', 'targetYearLevel'])],
            'Major selection period updated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?MajorSelectionPeriod $period = null): array
    {
        $academicYearId = $request->input('academic_year_id', $period?->academic_year_id);
        $programId = $request->input('program_id', $period?->program_id);
        $targetYearLevelId = $request->input('target_year_level_id', $period?->target_year_level_id);

        $attributes = $request->validate([
            'academic_year_id' => [
                $period === null ? 'required' : 'sometimes',
                'integer',
                Rule::exists('academic_years', 'id'),
            ],
            'program_id' => [
                $period === null ? 'required' : 'sometimes',
                'integer',
                Rule::exists('programs', 'id'),
                Rule::unique('major_selection_periods', 'program_id')
                    ->where(fn ($query) => $query
                        ->where('academic_year_id', $academicYearId)
                        ->where('target_year_level_id', $targetYearLevelId))
                    ->ignore($period),
            ],
            'target_year_level_id' => [
                $period === null ? 'required' : 'sometimes',
                'integer',
                Rule::exists('year_levels', 'id'),
            ],
            'opens_at' => [$period === null ? 'required' : 'sometimes', 'date'],
            'closes_at' => [$period === null ? 'required' : 'sometimes', 'date'],
            'allow_changes' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'closed', 'archived'])],
        ]);

        $opensAt = $attributes['opens_at'] ?? $period?->opens_at?->toDateTimeString();
        $closesAt = $attributes['closes_at'] ?? $period?->closes_at?->toDateTimeString();

        if ($opensAt !== null && $closesAt !== null && strtotime((string) $closesAt) <= strtotime((string) $opensAt)) {
            throw ValidationException::withMessages([
                'closes_at' => 'The closing time must be after the opening time.',
            ]);
        }

        if ($targetYearLevelId !== null) {
            $targetYearLevel = YearLevel::query()->find($targetYearLevelId);

            if ($targetYearLevel !== null && $targetYearLevel->level !== 3) {
                throw ValidationException::withMessages([
                    'target_year_level_id' => 'Major selection periods must target the third-year level.',
                ]);
            }
        }

        return $attributes;
    }
}
