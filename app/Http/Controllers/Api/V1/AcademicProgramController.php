<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreProgramRequest;
use App\Http\Requests\Academic\UpdateProgramRequest;
use App\Models\Program;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AcademicProgramController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Program::class);
        $result = AcademicDirectory::paginate(Program::query(), $request, ['code', 'name', 'description'], [
            'status' => 'status',
        ]);

        return ApiResponse::success([
            'programs' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Programs loaded.');
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create($request->validated());

        return ApiResponse::success(['program' => $program], 'Program created.', 201);
    }

    public function show(Program $program): JsonResponse
    {
        Gate::authorize('view', $program);

        return ApiResponse::success(['program' => $program], 'Program loaded.');
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $program->update($request->validated());

        return ApiResponse::success(['program' => $program->fresh()], 'Program updated.');
    }
}
