<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\YearLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AcademicOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Program::class);

        return ApiResponse::success([
            'programs' => Program::query()->orderBy('name')->get(['id', 'code', 'name', 'status']),
            'year_levels' => YearLevel::query()->orderBy('level')->get(['id', 'level', 'name']),
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->orderBy('id')->get([
                'id',
                'academic_year',
                'term',
                'name',
                'status',
                'is_current',
            ]),
        ], 'Academic options loaded.');
    }
}
