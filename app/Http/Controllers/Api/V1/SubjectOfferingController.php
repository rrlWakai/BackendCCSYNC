<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexSubjectOfferingRequest;
use App\Http\Requests\Academic\StoreSubjectOfferingRequest;
use App\Http\Requests\Academic\UpdateSubjectOfferingRequest;
use App\Http\Requests\Academic\UpdateSubjectOfferingStatusRequest;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Services\Academic\SubjectOfferingManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class SubjectOfferingController extends Controller
{
    public function __construct(private readonly SubjectOfferingManagementService $offerings) {}

    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', SubjectOffering::class);

        return ApiResponse::success([
            'terms' => AcademicTerm::query()
                ->orderByDesc('starts_on')
                ->orderBy('id')
                ->get(['id', 'academic_year', 'term', 'name', 'status', 'is_current']),
            'programs' => Program::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'status']),
            'sections' => Section::query()
                ->with([
                    'program:id,code,name,status',
                    'yearLevel:id,level,name',
                ])
                ->orderBy('code')
                ->get([
                    'id',
                    'program_id',
                    'year_level_id',
                    'academic_term_id',
                    'code',
                    'name',
                    'capacity',
                    'status',
                ]),
            'subjects' => Subject::query()
                ->orderBy('code')
                ->get(['id', 'code', 'title', 'units', 'status']),
        ], 'Subject offering options loaded.');
    }

    public function index(IndexSubjectOfferingRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SubjectOffering::class);
        $result = $this->offerings->directory($request->validated());

        return ApiResponse::success([
            'offerings' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Subject offerings loaded.');
    }

    public function store(StoreSubjectOfferingRequest $request): JsonResponse
    {
        $offering = $this->offerings->create($request->validated());

        return ApiResponse::success([
            'subject_offering' => $offering,
        ], 'Subject offering created.', 201);
    }

    public function show(SubjectOffering $subjectOffering): JsonResponse
    {
        Gate::authorize('view', $subjectOffering);

        return ApiResponse::success([
            'subject_offering' => $this->offerings->loadDetail($subjectOffering),
        ], 'Subject offering loaded.');
    }

    public function update(
        UpdateSubjectOfferingRequest $request,
        SubjectOffering $subjectOffering,
    ): JsonResponse {
        $offering = $this->offerings->update($subjectOffering, $request->validated());

        return ApiResponse::success([
            'subject_offering' => $offering,
        ], 'Subject offering updated.');
    }

    public function updateStatus(
        UpdateSubjectOfferingStatusRequest $request,
        SubjectOffering $subjectOffering,
    ): JsonResponse {
        $offering = $this->offerings->changeStatus($subjectOffering, $request->validated('status'));

        return ApiResponse::success([
            'subject_offering' => $offering,
        ], 'Subject offering status updated.');
    }
}
