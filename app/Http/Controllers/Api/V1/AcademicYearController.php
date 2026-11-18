<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\AcademicYear;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AcademicYearController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AcademicYear::class);

        $result = AcademicDirectory::paginate(
            AcademicYear::query(),
            $request,
            ['label'],
            ['status' => 'status'],
        );

        return ApiResponse::success([
            'academic_years' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Academic years loaded.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', AcademicYear::class);
        $attributes = $this->validatePayload($request);

        $year = AcademicYear::create($attributes);

        return ApiResponse::success(['academic_year' => $year], 'Academic year created.', 201);
    }

    public function show(AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize('view', AcademicYear::class);

        return ApiResponse::success(['academic_year' => $academicYear->load('academicTerms')], 'Academic year loaded.');
    }

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize('update', AcademicYear::class);
        $attributes = $this->validatePayload($request, $academicYear);

        $academicYear->update($attributes);

        return ApiResponse::success(['academic_year' => $academicYear->fresh()], 'Academic year updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?AcademicYear $academicYear = null): array
    {
        $attributes = $request->validate([
            'label' => [
                $academicYear === null ? 'required' : 'sometimes',
                'string',
                'regex:/^\d{4}-\d{4}$/',
                Rule::unique('academic_years', 'label')->ignore($academicYear),
            ],
            'starts_on' => [$academicYear === null ? 'required' : 'sometimes', 'date'],
            'ends_on' => [$academicYear === null ? 'required' : 'sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'completed', 'archived'])],
        ]);

        $startsOn = $attributes['starts_on'] ?? $academicYear?->starts_on?->toDateString();
        $endsOn = $attributes['ends_on'] ?? $academicYear?->ends_on?->toDateString();

        if ($startsOn !== null && $endsOn !== null && strtotime((string) $endsOn) < strtotime((string) $startsOn)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ends_on' => 'The end date must be on or after the start date.',
            ]);
        }

        $label = $attributes['label'] ?? $academicYear?->label;
        if (is_string($label) && preg_match('/^(\d{4})-(\d{4})$/', $label, $matches) === 1
            && (int) $matches[2] !== (int) $matches[1] + 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'label' => 'The second year must immediately follow the first year.',
            ]);
        }

        return $attributes;
    }
}
