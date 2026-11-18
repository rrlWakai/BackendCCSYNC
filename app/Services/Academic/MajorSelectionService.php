<?php

namespace App\Services\Academic;

use App\Models\AcademicYear;
use App\Models\Major;
use App\Models\MajorSelectionPeriod;
use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentMajorSelection;
use App\Models\StudentMajorSelectionChange;
use App\Models\User;
use App\Models\YearLevel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MajorSelectionService
{
    public function nextYearLevelFor(Student $student): ?YearLevel
    {
        $placement = $this->currentPlacement($student);

        if ($placement === null || $placement->yearLevel === null) {
            return null;
        }

        return YearLevel::query()
            ->where('level', '>', $placement->yearLevel->level)
            ->orderBy('level')
            ->first();
    }

    /**
     * @return Collection<int, MajorSelectionPeriod>
     */
    public function openPeriodsFor(Student $student): Collection
    {
        $placement = $this->currentPlacement($student);
        $targetLevelIds = $this->eligibleTargetLevelIds($student);

        if ($placement === null || $targetLevelIds === []) {
            return new Collection;
        }

        return MajorSelectionPeriod::query()
            ->with(['academicYear', 'program', 'targetYearLevel'])
            ->where('program_id', $placement->program_id)
            ->whereIn('target_year_level_id', $targetLevelIds)
            ->whereIn('status', ['planned', 'active'])
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>=', now())
            ->orderBy('closes_at')
            ->get();
    }

    /**
     * @return Collection<int, Major>
     */
    public function majorOptionsFor(Student $student): Collection
    {
        $placement = $this->currentPlacement($student);

        if ($placement === null) {
            return new Collection;
        }

        return Major::query()
            ->where('program_id', $placement->program_id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'program_id', 'code', 'name', 'description', 'status']);
    }

    public function currentSelectionFor(Student $student, ?AcademicYear $academicYear = null): ?StudentMajorSelection
    {
        $academicYear ??= $this->currentAcademicYear($student);

        if ($academicYear === null) {
            return null;
        }

        return StudentMajorSelection::query()
            ->with(['major', 'academicYear', 'period'])
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->first();
    }

    public function submit(
        Student $student,
        MajorSelectionPeriod $period,
        int $majorId,
        ?User $actor = null,
    ): StudentMajorSelection {
        return DB::transaction(function () use ($student, $period, $majorId, $actor): StudentMajorSelection {
            $period = MajorSelectionPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! in_array($period->status, ['planned', 'active'], true)) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period is not open.',
                ]);
            }

            if ($period->opens_at->isFuture() || $period->closes_at->isPast()) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period is outside its open window.',
                ]);
            }

            $placement = $this->currentPlacement($student);

            if ($placement === null) {
                throw ValidationException::withMessages([
                    'student' => 'A current academic placement is required before selecting a major.',
                ]);
            }

            if ((int) $period->program_id !== (int) $placement->program_id) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period does not belong to your program.',
                ]);
            }

            $nextLevel = $this->nextYearLevelFor($student);
            $eligibleTargets = $this->eligibleTargetLevelIds($student);
            if ($nextLevel === null || ! in_array((int) $period->target_year_level_id, $eligibleTargets, true)) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period does not match your next year level.',
                ]);
            }

            $major = Major::query()
                ->whereKey($majorId)
                ->where('program_id', $placement->program_id)
                ->where('status', 'active')
                ->first();

            if ($major === null) {
                throw ValidationException::withMessages([
                    'major_id' => 'Choose an active major offered by your program.',
                ]);
            }

            $existing = StudentMajorSelection::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $period->academic_year_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $period->allow_changes) {
                throw ValidationException::withMessages([
                    'period_id' => 'Changes to major selection are not allowed for this period.',
                ]);
            }

            if ($existing === null) {
                return StudentMajorSelection::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $period->academic_year_id,
                    'program_id' => $placement->program_id,
                    'major_id' => $major->id,
                    'period_id' => $period->id,
                    'status' => 'submitted',
                    'selected_at' => now(),
                ])->load(['major', 'academicYear', 'period']);
            }

            $previousMajorId = $existing->major_id;
            $existing->update([
                'major_id' => $major->id,
                'period_id' => $period->id,
                'status' => 'submitted',
                'selected_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'remarks' => null,
            ]);

            StudentMajorSelectionChange::create([
                'student_major_selection_id' => $existing->id,
                'previous_major_id' => $previousMajorId,
                'new_major_id' => $major->id,
                'changed_by' => $actor?->id,
                'reason' => 'Student updated major selection.',
            ]);

            return $existing->refresh()->load(['major', 'academicYear', 'period']);
        }, attempts: 3);
    }

    public function review(
        StudentMajorSelection $selection,
        string $status,
        User $actor,
        ?string $remarks = null,
    ): StudentMajorSelection {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose either approved or rejected.',
            ]);
        }

        return DB::transaction(function () use ($selection, $status, $actor, $remarks): StudentMajorSelection {
            $selection = StudentMajorSelection::query()->lockForUpdate()->findOrFail($selection->id);
            $selection->update([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'remarks' => $remarks,
            ]);

            return $selection->refresh()->load(['student.user:id,name,email', 'major', 'academicYear', 'period', 'reviewer:id,name']);
        }, attempts: 3);
    }

    public function approvedMajorFor(Student $student, AcademicYear $academicYear): ?Major
    {
        return StudentMajorSelection::query()
            ->with('major')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('status', 'approved')
            ->first()
            ?->major;
    }

    public function currentPlacement(Student $student): ?StudentAcademicPlacement
    {
        if ($student->current_placement_id !== null) {
            $placement = StudentAcademicPlacement::query()
                ->with(['yearLevel', 'major', 'section'])
                ->find($student->current_placement_id);

            if ($placement !== null) {
                return $placement;
            }
        }

        return StudentAcademicPlacement::query()
            ->with(['yearLevel', 'major', 'section'])
            ->where('student_id', $student->id)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    private function currentAcademicYear(Student $student): ?AcademicYear
    {
        $placement = $this->currentPlacement($student);

        return $placement?->academicYear;
    }

    /**
     * @return list<int>
     */
    private function eligibleTargetLevelIds(Student $student): array
    {
        $placement = $this->currentPlacement($student);

        if ($placement === null || $placement->yearLevel === null) {
            return [];
        }

        $ids = [];

        $nextLevel = $this->nextYearLevelFor($student);
        if ($nextLevel !== null) {
            $ids[] = (int) $nextLevel->id;
        }

        if ($placement->yearLevel->level >= 3 && $placement->major_id === null) {
            $ids[] = (int) $placement->year_level_id;
        }

        return array_values(array_unique($ids));
    }
}
