<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\StudentActivityLog;
use App\Models\StudentRemoval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentLifecycleService
{
    public const RECOVERY_DAYS = 7;

    public function recordActivity(
        Student $student,
        ?User $actor,
        string $action,
        string $description,
        array $metadata = [],
    ): StudentActivityLog {
        return StudentActivityLog::create([
            'student_id' => $student->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function remove(Student $student, User $actor): StudentRemoval
    {
        return DB::transaction(function () use ($student, $actor): StudentRemoval {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $user = User::query()->lockForUpdate()->findOrFail($student->user_id);
            $pending = StudentRemoval::query()
                ->where('student_id', $student->id)
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                throw ValidationException::withMessages([
                    'student' => 'This student is already pending permanent deletion.',
                ]);
            }

            if ($student->status === 'deleted') {
                throw ValidationException::withMessages([
                    'student' => 'This student record has already been permanently deleted.',
                ]);
            }

            $removedAt = now();
            $deadline = $removedAt->copy()->addDays(self::RECOVERY_DAYS);
            $removal = StudentRemoval::create([
                'student_id' => $student->id,
                'removed_by_user_id' => $actor->id,
                'status' => StudentRemoval::STATUS_PENDING,
                'previous_user_status' => $user->status,
                'previous_student_status' => $student->status,
                'removed_at' => $removedAt,
                'restore_deadline' => $deadline,
            ]);

            $student->forceFill(['status' => 'removed'])->save();
            $user->forceFill(['status' => 'inactive'])->save();
            $this->revokeAccess($user);

            $this->recordActivity(
                $student,
                $actor,
                'student_removed',
                'Student account access was removed and the record was scheduled for permanent deletion.',
                [
                    'removal_id' => $removal->id,
                    'restore_deadline' => $deadline->toIso8601String(),
                ],
            );

            return $removal->refresh()->load(['student.user', 'removedBy']);
        }, attempts: 3);
    }

    public function restore(Student $student, User $actor): StudentRemoval
    {
        return DB::transaction(function () use ($student, $actor): StudentRemoval {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $user = User::query()->lockForUpdate()->findOrFail($student->user_id);
            $removal = StudentRemoval::query()
                ->where('student_id', $student->id)
                ->pending()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($removal === null) {
                throw ValidationException::withMessages([
                    'student' => 'This student is not pending permanent deletion.',
                ]);
            }

            if ($removal->isExpired()) {
                throw ValidationException::withMessages([
                    'student' => 'The seven-day recovery period has expired. This student can no longer be restored.',
                ]);
            }

            $restoredAt = now();
            $removal->update([
                'status' => StudentRemoval::STATUS_RESTORED,
                'restored_by_user_id' => $actor->id,
                'restored_at' => $restoredAt,
            ]);

            $student->forceFill([
                'status' => $removal->previous_student_status ?: 'active',
            ])->save();
            $user->forceFill([
                'status' => $removal->previous_user_status ?: 'active',
            ])->save();

            $this->recordActivity(
                $student,
                $actor,
                'student_restored',
                'Student account access was restored before the permanent deletion deadline.',
                [
                    'removal_id' => $removal->id,
                    'restored_at' => $restoredAt->toIso8601String(),
                ],
            );

            return $removal->refresh()->load(['student.user', 'restoredBy']);
        }, attempts: 3);
    }

    public function purgeDue(): int
    {
        $removalIds = StudentRemoval::query()
            ->dueForDeletion()
            ->orderBy('id')
            ->pluck('id');

        $processed = 0;

        foreach ($removalIds as $removalId) {
            if ($this->purge((int) $removalId)) {
                $processed++;
            }
        }

        return $processed;
    }

    public function purge(int $removalId): bool
    {
        return DB::transaction(function () use ($removalId): bool {
            $removal = StudentRemoval::query()->lockForUpdate()->find($removalId);

            if ($removal === null
                || $removal->status !== StudentRemoval::STATUS_PENDING
                || ! $removal->isExpired()) {
                return false;
            }

            $student = Student::query()->lockForUpdate()->find($removal->student_id);

            if ($student === null) {
                return false;
            }

            $user = User::query()->lockForUpdate()->find($student->user_id);
            $processedAt = now();

            $removal->update([
                'status' => StudentRemoval::STATUS_PERMANENTLY_DELETED,
                'permanent_deletion_started_at' => $processedAt,
                'permanently_deleted_at' => $processedAt,
            ]);

            if ($user !== null) {
                $user->forceFill([
                    'name' => 'Removed Student '.$student->id,
                    'email' => 'removed-student-'.$user->id.'@deleted.invalid',
                    'password' => Str::random(64),
                    'status' => 'inactive',
                    'email_verified_at' => null,
                    'remember_token' => null,
                    'last_login_at' => null,
                ])->save();

                $this->revokeAccess($user);
            }

            $student->forceFill(['status' => 'deleted'])->save();

            $this->recordActivity(
                $student,
                null,
                'student_permanently_deleted',
                'The student account identity was anonymized after the seven-day recovery period.',
                [
                    'removal_id' => $removal->id,
                    'processed_at' => $processedAt->toIso8601String(),
                ],
            );

            return true;
        }, attempts: 3);
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        $sessionDriver = config('session.driver');
        $sessionTable = config('session.table', 'sessions');

        if ($sessionDriver === 'database' && is_string($sessionTable) && Schema::hasTable($sessionTable)) {
            DB::table($sessionTable)->where('user_id', $user->id)->delete();
        }
    }
}
