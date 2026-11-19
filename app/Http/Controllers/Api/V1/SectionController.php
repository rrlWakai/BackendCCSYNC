<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreSectionRequest;
use App\Http\Requests\Academic\UpdateSectionRequest;
use App\Models\AcademicTerm;
use App\Models\Major;
use App\Models\Program;
use App\Models\Section;
use App\Models\YearLevel;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SectionController extends Controller
{
    private const OFFICIAL_MAJOR_CODES = [
        'BSIT' => ['WMAD', 'SMP', 'NA'],
        'BSCS' => ['AMG', 'IS'],
    ];

    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Section::class);
        $result = AcademicDirectory::paginate(
            Section::query()->with(['program:id,code,name', 'yearLevel:id,level,name', 'major:id,program_id,code,name', 'academicTerm:id,academic_year,term,name']),
            $request,
            ['code', 'name'],
            [
                'program_id' => 'program_id',
                'year_level_id' => 'year_level_id',
                'major_id' => 'major_id',
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
        $this->validateSectionCode($attributes);
        $section = Section::create($attributes);

        return ApiResponse::success(
            ['section' => $section->load(['program', 'yearLevel', 'major', 'academicTerm'])],
            'Section created.',
            201,
        );
    }

    public function show(Section $section): JsonResponse
    {
        Gate::authorize('view', $section);

        return ApiResponse::success(['section' => $section->load(['program', 'yearLevel', 'major', 'academicTerm'])], 'Section loaded.');
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $attributes = $request->validated();
        $this->validateRelatedRecords($attributes, $section);

        if ($this->sectionIdentityChanged($attributes, $section)) {
            $this->validateSectionCode($attributes, $section);
        }

        if (
            isset($attributes['academic_term_id'])
            && (int) $attributes['academic_term_id'] !== $section->academic_term_id
            && $section->subjectOfferings()->exists()
        ) {
            throw ValidationException::withMessages([
                'academic_term_id' => 'A section with class assignments cannot be moved to another academic term.',
            ]);
        }

        $section->update($attributes);

        return ApiResponse::success(['section' => $section->fresh()->load(['program', 'yearLevel', 'major', 'academicTerm'])], 'Section updated.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sectionIdentityChanged(array $attributes, Section $section): bool
    {
        foreach (['program_id', 'year_level_id', 'major_id', 'code'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $current = Str::upper(trim((string) ($section->{$field} ?? '')));
            $incoming = Str::upper(trim((string) $attributes[$field]));

            if ($incoming !== $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateSectionCode(array $attributes, ?Section $section = null): void
    {
        $programId = $attributes['program_id'] ?? $section?->program_id;
        $yearLevelId = $attributes['year_level_id'] ?? $section?->year_level_id;
        $majorId = array_key_exists('major_id', $attributes)
            ? $attributes['major_id']
            : $section?->major_id;
        $code = Str::upper(trim((string) ($attributes['code'] ?? $section?->code ?? '')));

        if ($programId === null || $yearLevelId === null) {
            return;
        }

        $program = Program::find($programId);
        $yearLevel = YearLevel::find($yearLevelId);

        if ($program === null || $yearLevel === null) {
            return;
        }

        if ((int) $yearLevel->level < 3) {
            if ($majorId !== null) {
                throw ValidationException::withMessages([
                    'major_id' => 'First- and second-year sections use the common program curriculum; choose a specialization only from third year.',
                ]);
            }

            return;
        }

        if ($majorId === null) {
            throw ValidationException::withMessages([
                'major_id' => 'Third- and fourth-year sections require a specialization.',
            ]);
        }

        $major = Major::find($majorId);

        if ($major === null) {
            return;
        }

        $programCode = Str::upper((string) $program->code);
        $majorCode = Str::upper((string) $major->code);
        $allowedMajorCodes = self::OFFICIAL_MAJOR_CODES[$programCode] ?? null;

        if ($allowedMajorCodes !== null && ! in_array($majorCode, $allowedMajorCodes, true)) {
            throw ValidationException::withMessages([
                'major_id' => "{$programCode} does not offer the {$majorCode} specialization.",
            ]);
        }

        if (! preg_match('/^([34])([A-Z]+)([1-9]\d*)$/', $code, $matches)) {
            throw ValidationException::withMessages([
                'code' => 'Use the official section code format, such as 3WMAD1, with no spaces or hyphens.',
            ]);
        }

        if ((int) $matches[1] !== (int) $yearLevel->level) {
            throw ValidationException::withMessages([
                'code' => "The section code must begin with {$yearLevel->level} for this year level.",
            ]);
        }

        if ($matches[2] !== $majorCode) {
            throw ValidationException::withMessages([
                'code' => "The section code must include the {$majorCode} specialization code.",
            ]);
        }
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

        $programId = $attributes['program_id'] ?? $section?->program_id;

        if (array_key_exists('major_id', $attributes) && $attributes['major_id'] !== null) {
            $major = Major::query()->find($attributes['major_id']);

            if ($major === null || (int) $major->program_id !== (int) $programId) {
                throw ValidationException::withMessages([
                    'major_id' => 'The selected major must belong to the selected program.',
                ]);
            }

            $yearLevelId = $attributes['year_level_id'] ?? $section?->year_level_id;
            $yearLevel = YearLevel::query()->find($yearLevelId);
            if ($yearLevel !== null && $yearLevel->level < 3) {
                throw ValidationException::withMessages([
                    'major_id' => 'First- and second-year sections use the common program curriculum; choose a specialization only from third year.',
                ]);
            }
        }
    }
}
