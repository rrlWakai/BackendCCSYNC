<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\IndexStudentScheduleConflictsRequest;
use App\Models\Role;
use App\Services\Scheduling\StudentScheduleConflictService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StudentScheduleConflictController extends Controller
{
    public function __construct(private readonly StudentScheduleConflictService $conflicts) {}

    public function options(IndexStudentScheduleConflictsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->conflicts->options(
                $request->user(),
                $request->validated('academic_term_id'),
            ),
            'Student schedule conflict filters loaded.',
        );
    }

    public function index(IndexStudentScheduleConflictsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->conflicts->administrativePage($request->validated(), $request->user()),
            'Student schedule conflicts loaded.',
        );
    }

    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole(Role::STUDENT), 403);
        $student = $request->user()->student;
        if ($student === null) {
            throw new NotFoundHttpException('Student profile not found.');
        }

        return ApiResponse::success(
            $this->conflicts->ownPage($student),
            'Your schedule conflicts loaded.',
        );
    }
}
