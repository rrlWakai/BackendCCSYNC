<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class RegistrationOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $sections = Section::query()
            ->where('sections.status', 'active')
            ->whereHas('program', fn ($query) => $query->where('status', 'active'))
            ->whereHas('academicTerm', fn ($query) => $query
                ->where('status', 'active')
                ->where('is_current', true))
            ->with(['program:id,code,name', 'yearLevel:id,level,name'])
            ->orderBy('program_id')
            ->orderBy('year_level_id')
            ->orderBy('code')
            ->get();

        return ApiResponse::success([
            'programs' => Program::query()
                ->where('status', 'active')
                ->whereHas('sections', fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('academicTerm', fn ($termQuery) => $termQuery
                        ->where('status', 'active')
                        ->where('is_current', true)))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'year_levels' => $sections->pluck('yearLevel')->unique('id')->sortBy('level')->values(),
            'sections' => $sections->map(fn (Section $section): array => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'program_id' => $section->program_id,
                'year_level_id' => $section->year_level_id,
                'program' => [
                    'code' => $section->program->code,
                    'name' => $section->program->name,
                ],
                'year_level' => [
                    'level' => $section->yearLevel->level,
                    'name' => $section->yearLevel->name,
                ],
            ])->values(),
            'departments' => Department::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ], 'Registration options loaded.');
    }
}
