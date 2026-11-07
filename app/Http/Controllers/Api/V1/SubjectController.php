<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Http\Requests\Academic\StoreSubjectRequest;
use App\Http\Requests\Academic\UpdateSubjectRequest;
use App\Models\Subject;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class SubjectController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Subject::class);
        $result = AcademicDirectory::paginate(Subject::query(), $request, ['code', 'title', 'description'], [
            'status' => 'status',
        ]);

        return ApiResponse::success([
            'subjects' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Subjects loaded.');
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Subject::create($request->validated());

        return ApiResponse::success(['subject' => $subject], 'Subject created.', 201);
    }

    public function show(Subject $subject): JsonResponse
    {
        Gate::authorize('view', $subject);

        return ApiResponse::success(['subject' => $subject], 'Subject loaded.');
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validated());

        return ApiResponse::success(['subject' => $subject->fresh()], 'Subject updated.');
    }
}
