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
    private const MAJOR_START_LEVEL = 3;

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
     * @return array{
     *     eligible: bool,
     *     reason: string|null,
     *     current_year_level: array<string, mixed>|null,
     *     target_year_level: array<string, mixed>|null,
     *     has_major: bool
     * }
     */
    public function eligibilityFor(Student $student): array
    {
        $placement = $this->currentPlacement($student);
        $currentLevel = $placement?->yearLevel;

        $result = [
            'eligible' => false,
            'reason' => 'placement_missing',
            'current_year_level' => $currentLevel === null ? null : [
                'id' => $currentLevel->id,
                'level' => $currentLevel->level,
                'name' => $currentLevel->name,
            ],
            'target_year_level' => null,
            'has_major' => $placement?->major_id !== null,
        ];

        if ($placement === null || $currentLevel === null) {
            return $result;
        }

        if ($placement->major_id !== null) {
            $result['reason'] = 'major_already_assigned';

            return $result;
        }

        if ($currentLevel->level < 2) {
            $result['reason'] = 'not_yet_eligible';

            return $result;
        }

        if ($currentLevel->level > self::MAJOR_START_LEVEL) {
            $result['reason'] = 'not_eligible';

            return $result;
        }

        if ($currentLevel->level === 2) {
            $nextLevel = $this->nextYearLevelFor($student);

            if ($nextLevel === null || $nextLevel->level !== self::MAJOR_START_LEVEL) {
                $result['reason'] = 'target_level_missing';

                return $result;
            }

            $targetLevel = $nextLevel;
        } else {
            $targetLevel = $currentLevel;
        }

        $result['eligible'] = true;
        $result['reason'] = null;
        $result['target_year_level'] = [
            'id' => $targetLevel->id,
            'level' => $targetLevel->level,
            'name' => $targetLevel->name,
        ];

        return $result;
    }

    /**
     * @return Collection<int, MajorSelectionPeriod>
     */
    public function openPeriodsFor(Student $student): Collection
    {
        $placement = $this->currentPlacement($student);
        $eligibility = $this->eligibilityFor($student);
        $targetLevel = $eligibility['target_year_level'];

        if (
            ! $eligibility['eligible']
            || $targetLevel === null
            || $placement === null
            || $placement->yearLevel === null
            || $placement->academicYear === null
        ) {
            return new Collection;
        }

        $query = MajorSelectionPeriod::query()
            ->with(['academicYear', 'program', 'targetYearLevel'])
            ->where('program_id', $placement->program_id)
            ->where('target_year_level_id', $targetLevel['id'])
            ->whereIn('status', ['planned', 'active'])
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>=', now());

        if ($placement->yearLevel->level === 2) {
            $nextAcademicYear = $this->nextAcademicYearFor($placement->academicYear);

            if ($nextAcademicYear === null) {
                return new Collection;
            }

            $query->where('academic_year_id', $nextAcademicYear->id);
        } else {
            $query->whereHas('academicYear', fn ($academicYearQuery) => $academicYearQuery
                ->where('starts_on', '>=', $placement->academicYear->starts_on));
        }

        return $query
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
        $query = StudentMajorSelection::query()
            ->with(['major', 'academicYear', 'period'])
            ->where('student_id', $student->id);

        if ($academicYear !== null) {
            $query->where('academic_year_id', $academicYear->id);
        }

        return $query->latest('id')->first();
    }

    public function submit(
        Student $student,
        MajorSelectionPeriod $period,
        int $majorId,
        ?User $actor = null,
    ): StudentMajorSelection {
        return DB::transaction(function () use ($student, $period, $majorId, $actor): StudentMajorSelection {
            $period = MajorSelectionPeriod::query()
                ->with('academicYear')
                ->lockForUpdate()
                ->findOrFail($period->id);

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

            $eligibility = $this->eligibilityFor($student);
            $targetLevel = $eligibility['target_year_level'];

            if (
                ! $eligibility['eligible']
                || $targetLevel === null
                || (int) $period->target_year_level_id !== (int) $targetLevel['id']
            ) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period does not match your third-year transition.',
                ]);
            }

            if (! $this->periodAcademicYearIsEligible($period, $placement)) {
                throw ValidationException::withMessages([
                    'period_id' => 'This major selection period is not associated with your eligible academic year.',
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

    public function correct(
        StudentMajorSelection $selection,
        int $majorId,
        User $actor,
        string $reason,
    ): StudentMajorSelection {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A correction reason is required.',
            ]);
        }

        return DB::transaction(function () use ($selection, $majorId, $actor, $reason): StudentMajorSelection {
            $selection = StudentMajorSelection::query()->lockForUpdate()->findOrFail($selection->id);

            $major = Major::query()
                ->whereKey($majorId)
                ->where('program_id', $selection->program_id)
                ->where('status', 'active')
                ->first();

            if ($major === null) {
                throw ValidationException::withMessages([
                    'major_id' => 'Choose an active major offered by the student program.',
                ]);
            }

            if ((int) $selection->major_id === (int) $major->id) {
                throw ValidationException::withMessages([
                    'major_id' => 'Choose a different major for this correction.',
                ]);
            }

            $previousMajorId = $selection->major_id;

            $selection->update([
                'major_id' => $major->id,
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'remarks' => $reason,
            ]);

            StudentMajorSelectionChange::create([
                'student_major_selection_id' => $selection->id,
                'previous_major_id' => $previousMajorId,
                'new_major_id' => $major->id,
                'changed_by' => $actor->id,
                'reason' => $reason,
            ]);

            return $selection->refresh()->load([
                'student.user:id,name,email',
                'major',
                'academicYear',
                'period',
                'reviewer:id,name',
            ]);
        }, attempts: 3);
    }

    public function selectedMajorFor(Student $student, AcademicYear $academicYear): ?Major
    {
        return StudentMajorSelection::query()
            ->with('major')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->latest('id')
            ->first()
            ?->major;
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
                ->with(['academicYear', 'yearLevel', 'major', 'section'])
                ->find($student->current_placement_id);

            if ($placement !== null) {
                return $placement;
            }
        }

        return StudentAcademicPlacement::query()
            ->with(['academicYear', 'yearLevel', 'major', 'section'])
            ->where('student_id', $student->id)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    private function eligibleTargetLevelIds(Student $student): array
    {
        $eligibility = $this->eligibilityFor($student);

        if (! $eligibility['eligible'] || $eligibility['target_year_level'] === null) {
            return [];
        }

        return [(int) $eligibility['target_year_level']['id']];
    }

    private function periodAcademicYearIsEligible(
        MajorSelectionPeriod $period,
        StudentAcademicPlacement $placement,
    ): bool {
        if (
            $period->academicYear === null
            || $placement->academicYear === null
            || $placement->yearLevel === null
        ) {
            return false;
        }

        if ($placement->yearLevel->level === 2) {
            $nextAcademicYear = $this->nextAcademicYearFor($placement->academicYear);

            return $nextAcademicYear !== null
                && (int) $period->academic_year_id === (int) $nextAcademicYear->id;
        }

        if ($placement->yearLevel->level === self::MAJOR_START_LEVEL) {
            return $period->academicYear->starts_on->greaterThanOrEqualTo($placement->academicYear->starts_on);
        }

        return false;
    }

    private function nextAcademicYearFor(AcademicYear $academicYear): ?AcademicYear
    {
        return AcademicYear::query()
            ->where('id', '!=', $academicYear->id)
            ->where('starts_on', '>', $academicYear->starts_on)
            ->orderBy('starts_on')
            ->first();
    }
}
