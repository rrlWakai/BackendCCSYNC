<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MajorSelectionPeriod;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentMajorSelection;
use App\Services\Academic\MajorSelectionService;
use App\Services\Academic\PlacementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StudentPlacementController extends Controller
{
    public function __construct(
        private readonly PlacementService $placements,
        private readonly MajorSelectionService $selections,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $student = $this->student($request);
        Gate::authorize('view', $student);

        $current = $this->placements->currentFor($student);
        $history = StudentAcademicPlacement::query()
            ->with(['academicYear', 'academicTerm', 'program', 'yearLevel', 'major', 'section'])
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success([
            'current_placement' => $current === null ? null : $this->placements->toArray($current),
            'placement_history' => $history->map(fn (StudentAcademicPlacement $placement): array => $this->placements->toArray($placement))->all(),
            'major_selection_periods' => $this->selections->openPeriodsFor($student)
                ->map(fn (MajorSelectionPeriod $period): array => $this->periodArray($period))
                ->all(),
            'major_options' => $this->selections->majorOptionsFor($student)
                ->map(fn ($major): array => [
                    'id' => $major->id,
                    'code' => $major->code,
                    'name' => $major->name,
                    'description' => $major->description,
                ])
                ->all(),
            'major_selection' => $this->selectionArray($this->selections->currentSelectionFor($student)),
        ], 'Student placement loaded.');
    }

    public function selectMajor(Request $request, MajorSelectionPeriod $period): JsonResponse
    {
        $student = $this->student($request);
        Gate::authorize('view', $student);

        $attributes = $request->validate([
            'major_id' => ['required', 'integer', Rule::exists('majors', 'id')],
        ]);

        $selection = $this->selections->submit(
            $student,
            $period,
            (int) $attributes['major_id'],
            $request->user(),
        );

        return ApiResponse::success([
            'major_selection' => $this->selectionArray($selection),
        ], 'Major selection submitted.', 201);
    }

    private function student(Request $request): \App\Models\Student
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw new NotFoundHttpException('Student profile not found.');
        }

        return $student;
    }

    /**
     * @return array<string, mixed>
     */
    private function periodArray(MajorSelectionPeriod $period): array
    {
        return [
            'id' => $period->id,
            'opens_at' => $period->opens_at?->toIso8601String(),
            'closes_at' => $period->closes_at?->toIso8601String(),
            'allow_changes' => $period->allow_changes,
            'status' => $period->status,
            'academic_year' => $period->academicYear === null ? null : [
                'id' => $period->academicYear->id,
                'label' => $period->academicYear->label,
            ],
            'program' => $period->program === null ? null : [
                'id' => $period->program->id,
                'code' => $period->program->code,
                'name' => $period->program->name,
            ],
            'target_year_level' => $period->targetYearLevel === null ? null : [
                'id' => $period->targetYearLevel->id,
                'level' => $period->targetYearLevel->level,
                'name' => $period->targetYearLevel->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectionArray(?StudentMajorSelection $selection): ?array
    {
        if ($selection === null) {
            return null;
        }

        return [
            'id' => $selection->id,
            'status' => $selection->status,
            'selected_at' => $selection->selected_at?->toIso8601String(),
            'reviewed_at' => $selection->reviewed_at?->toIso8601String(),
            'remarks' => $selection->remarks,
            'major' => $selection->major === null ? null : [
                'id' => $selection->major->id,
                'code' => $selection->major->code,
                'name' => $selection->major->name,
            ],
            'academic_year' => $selection->academicYear === null ? null : [
                'id' => $selection->academicYear->id,
                'label' => $selection->academicYear->label,
            ],
            'period' => $selection->period === null ? null : $this->periodArray($selection->period),
        ];
    }
}
