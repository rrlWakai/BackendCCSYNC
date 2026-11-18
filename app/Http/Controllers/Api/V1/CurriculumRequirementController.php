<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\CurriculumRequirement;
use App\Models\Major;
use App\Models\Program;
use App\Models\YearLevel;
use App\Services\Academic\CurriculumService;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CurriculumRequirementController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CurriculumRequirement::class);

        $query = CurriculumRequirement::query()->with([
            'program:id,code,name',
            'yearLevel:id,level,name',
            'major:id,program_id,code,name',
            'subject:id,code,title,units,status',
        ]);

        foreach (['program_id', 'year_level_id', 'semester', 'major_id', 'subject_id', 'classification', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $result = AcademicDirectory::paginate($query, $request, ['requirement_type', 'semester', 'classification'], []);

        return ApiResponse::success([
            'curriculum_requirements' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Curriculum requirements loaded.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CurriculumRequirement::class);
        $attributes = $this->validatePayload($request);

        $requirement = CurriculumRequirement::create($attributes);

        return ApiResponse::success(
            ['curriculum_requirement' => $requirement->load(['program', 'yearLevel', 'major', 'subject'])],
            'Curriculum requirement created.',
            201,
        );
    }

    public function update(Request $request, CurriculumRequirement $curriculumRequirement): JsonResponse
    {
        Gate::authorize('update', CurriculumRequirement::class);
        $attributes = $this->validatePayload($request, $curriculumRequirement);

        $curriculumRequirement->update($attributes);

        return ApiResponse::success(
            ['curriculum_requirement' => $curriculumRequirement->fresh()->load(['program', 'yearLevel', 'major', 'subject'])],
            'Curriculum requirement updated.',
        );
    }

    public function destroy(CurriculumRequirement $curriculumRequirement): JsonResponse
    {
        Gate::authorize('update', CurriculumRequirement::class);
        $curriculumRequirement->delete();

        return ApiResponse::success(null, 'Curriculum requirement removed.');
    }

    public function subjects(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CurriculumRequirement::class);

        $attributes = $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'year_level_id' => ['required', 'integer', Rule::exists('year_levels', 'id')],
            'semester' => ['sometimes', 'nullable', Rule::in(CurriculumRequirement::SEMESTERS)],
            'classification' => ['sometimes', Rule::in(CurriculumRequirement::CLASSIFICATIONS)],
            'major_id' => ['sometimes', 'nullable', 'integer', Rule::exists('majors', 'id')],
        ]);

        $program = Program::query()->findOrFail($attributes['program_id']);
        $yearLevel = YearLevel::query()->findOrFail($attributes['year_level_id']);
        $classification = $attributes['classification'] ?? null;
        $major = isset($attributes['major_id']) && $attributes['major_id'] !== null
            ? Major::query()->findOrFail($attributes['major_id'])
            : null;

        if ($classification === 'common' && $major !== null) {
            throw ValidationException::withMessages([
                'major_id' => 'Common and minor curriculum mappings cannot be assigned to a specific major.',
            ]);
        }

        if ($classification === 'major' && $major === null) {
            throw ValidationException::withMessages([
                'major_id' => 'Major-specific curriculum mappings require a major.',
            ]);
        }

        if ($major !== null && (int) $major->program_id !== (int) $program->id) {
            throw ValidationException::withMessages([
                'major_id' => 'The selected major must belong to the selected program.',
            ]);
        }

        return ApiResponse::success([
            'subjects' => $this->curriculum->subjectsFor(
                $program,
                $yearLevel,
                $major,
                $attributes['semester'] ?? null,
                $classification,
            ),
        ], 'Curriculum subjects loaded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?CurriculumRequirement $requirement = null): array
    {
        $programId = $request->input('program_id', $requirement?->program_id);
        $yearLevelId = $request->input('year_level_id', $requirement?->year_level_id);
        $subjectId = $request->input('subject_id', $requirement?->subject_id);
        $majorId = $request->input('major_id', $requirement?->major_id);
        $semester = $request->input('semester', $requirement?->semester);
        $classification = $request->input('classification', $requirement?->classification);

        $attributes = $request->validate([
            'program_id' => [$requirement === null ? 'required' : 'sometimes', 'integer', Rule::exists('programs', 'id')],
            'year_level_id' => [$requirement === null ? 'required' : 'sometimes', 'integer', Rule::exists('year_levels', 'id')],
            'semester' => [$requirement === null ? 'required' : 'sometimes', Rule::in(CurriculumRequirement::SEMESTERS)],
            'major_id' => ['sometimes', 'nullable', 'integer', Rule::exists('majors', 'id')],
            'classification' => [$requirement === null ? 'required' : 'sometimes', Rule::in(CurriculumRequirement::CLASSIFICATIONS)],
            'subject_id' => [$requirement === null ? 'required' : 'sometimes', 'integer', Rule::exists('subjects', 'id')],
            'requirement_type' => ['sometimes', Rule::in(['core', 'minor', 'major', 'elective'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if ($classification === null) {
            $classification = $majorId === null ? 'common' : 'major';
        }

        if ($classification === 'common' && $majorId !== null) {
            throw ValidationException::withMessages([
                'major_id' => 'Common and minor curriculum mappings cannot be assigned to a specific major.',
            ]);
        }

        if ($classification === 'major' && $majorId === null) {
            throw ValidationException::withMessages([
                'major_id' => 'Major-specific curriculum mappings require a major.',
            ]);
        }

        if ($majorId !== null) {
            $major = Major::query()->find($majorId);
            if ($major === null || (int) $major->program_id !== (int) $programId) {
                throw ValidationException::withMessages([
                    'major_id' => 'The selected major must belong to the selected program.',
                ]);
            }
        }

        $exists = CurriculumRequirement::query()
            ->where('program_id', $programId)
            ->where('year_level_id', $yearLevelId)
            ->where('subject_id', $subjectId)
            ->when(
                $semester === null,
                fn ($query) => $query->whereNull('semester'),
                fn ($query) => $query->where('semester', $semester),
            )
            ->when(
                $majorId === null,
                fn ($query) => $query->whereNull('major_id'),
                fn ($query) => $query->where('major_id', $majorId),
            )
            ->when($requirement !== null, fn ($query) => $query->whereKeyNot($requirement->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is already part of the selected program, year level, semester, and major scope.',
            ]);
        }

        $attributes['semester'] = $semester;
        $attributes['classification'] = $classification;

        return $attributes;
    }
}
