<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\Role;
use App\Models\StudentMajorSelection;
use App\Services\Academic\MajorSelectionService;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MajorSelectionController extends Controller
{
    public function __construct(private readonly MajorSelectionService $selections) {}

    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', StudentMajorSelection::class);

        $query = StudentMajorSelection::query()->with([
            'student:id,user_id,student_number,program_id',
            'student.user:id,name,email,status',
            'major:id,program_id,code,name',
            'academicYear:id,label,status',
            'period:id,opens_at,closes_at,allow_changes,status',
            'reviewer:id,name',
        ]);

        foreach (['program_id', 'major_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->integer('academic_year_id'));
        }

        $result = AcademicDirectory::paginate($query, $request, [], []);

        return ApiResponse::success([
            'major_selections' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Major selections loaded.');
    }

    public function review(Request $request, StudentMajorSelection $selection): JsonResponse
    {
        abort_unless($request->user()?->hasRole(Role::ADMINISTRATOR) ?? false, 403);

        $attributes = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $selection = $this->selections->review(
            $selection,
            $attributes['status'],
            $request->user(),
            $attributes['remarks'] ?? null,
        );

        return ApiResponse::success(['major_selection' => $selection], 'Major selection reviewed.');
    }
}
