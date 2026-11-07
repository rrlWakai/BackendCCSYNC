<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreSectionRequest;
use App\Http\Requests\Academic\UpdateSectionRequest;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\Section;
use App\Models\YearLevel;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SectionController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Section::class);
        $result = AcademicDirectory::paginate(
            Section::query()->with(['program:id,code,name', 'yearLevel:id,level,name', 'academicTerm:id,academic_year,term,name']),
            $request,
            ['code', 'name'],
            [
                'program_id' => 'program_id',
                'year_level_id' => 'year_level_id',
                'academic_term_id' => 'academic_term_id',
                'status' => 'status',
            ],
        );

        return ApiResponse::success([
            'sections' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Sections loaded.');
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $this->validateRelatedRecords($attributes);
        $section = Section::create($attributes);

        return ApiResponse::success(
            ['section' => $section->load(['program', 'yearLevel', 'academicTerm'])],
            'Section created.',
            201,
        );
    }

    public function show(Section $section): JsonResponse
    {
        Gate::authorize('view', $section);

        return ApiResponse::success(['section' => $section->load(['program', 'yearLevel', 'academicTerm'])], 'Section loaded.');
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $attributes = $request->validated();
        $this->validateRelatedRecords($attributes, $section);

        if (
            isset($attributes['academic_term_id'])
            && (int) $attributes['academic_term_id'] !== $section->academic_term_id
            && $section->subjectOfferings()->exists()
        ) {
            throw ValidationException::withMessages([
                'academic_term_id' => 'A section with subject offerings cannot be moved to another academic term.',
            ]);
        }

        $section->update($attributes);

        return ApiResponse::success(['section' => $section->fresh()->load(['program', 'yearLevel', 'academicTerm'])], 'Section updated.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateRelatedRecords(array $attributes, ?Section $section = null): void
    {
        if (
            isset($attributes['program_id'])
            && (int) $attributes['program_id'] !== $section?->program_id
            && Program::whereKey($attributes['program_id'])->where('status', 'inactive')->exists()
        ) {
            throw ValidationException::withMessages(['program_id' => 'Choose an active program.']);
        }

        if (
            isset($attributes['academic_term_id'])
            && (int) $attributes['academic_term_id'] !== $section?->academic_term_id
            && AcademicTerm::whereKey($attributes['academic_term_id'])->where('status', 'archived')->exists()
        ) {
            throw ValidationException::withMessages(['academic_term_id' => 'Choose a non-archived academic term.']);
        }

        if (isset($attributes['year_level_id']) && ! YearLevel::whereKey($attributes['year_level_id'])->exists()) {
            throw ValidationException::withMessages(['year_level_id' => 'Choose an existing year level.']);
        }
    }
}
