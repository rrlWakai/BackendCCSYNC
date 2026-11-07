<?php

namespace App\Services\Attendance;

use App\Models\AttendanceQrToken;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Role;
use App\Models\StudentAttendance;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceManagementService
{
    public function createSession(ClassSchedule $schedule, string $classDate, User $actor): AttendanceSession
    {
        return DB::transaction(function () use ($schedule, $classDate, $actor): AttendanceSession {
            $lockedSchedule = ClassSchedule::query()
                ->lockForUpdate()
                ->findOrFail($schedule->id);
            $offering = $lockedSchedule->subjectOffering()->with('academicTerm')->firstOrFail();
            $this->assertScheduleUsable($lockedSchedule, $actor);

            $timezone = config('app.timezone');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $classDate, $timezone);
            $term = $offering->academicTerm;

            if ($date->isAfter(CarbonImmutable::today($timezone))
                || $date->dayOfWeekIso !== $lockedSchedule->day_of_week
                || $date->toDateString() < $term->starts_on->toDateString()
                || $date->toDateString() > $term->ends_on->toDateString()) {
                throw ValidationException::withMessages([
                    'class_date' => 'The class date must be a past or current scheduled weekday within the academic term.',
                ]);
            }

            if (AttendanceSession::query()
                ->where('class_schedule_id', $lockedSchedule->id)
                ->whereDate('class_date', $date->toDateString())
                ->exists()) {
                throw ValidationException::withMessages([
                    'class_date' => 'An attendance session already exists for this scheduled class date.',
                ]);
            }

            $session = AttendanceSession::create([
                'class_schedule_id' => $lockedSchedule->id,
                'class_date' => $date->toDateString(),
                'status' => 'open',
                'created_by_user_id' => $actor->id,
            ]);

            return $session->fresh();
        }, attempts: 3);
    }

    public function rosterForSession(AttendanceSession $session): Collection
    {
        $session->loadMissing('classSchedule.subjectOffering');

        return $this->eligibleEnrollments($session)
            ->with(['student.user:id,name,status'])
            ->orderBy('students.student_number')
            ->get();
    }

    public function eligibleEnrollmentsForSession(AttendanceSession $session): Builder
    {
        return $this->eligibleEnrollments($session);
    }

    public function eligibleEnrollmentForStudent(
        AttendanceSession $session,
        int $studentId,
    ): ?StudentSubjectEnrollment {
        return $this->eligibleEnrollments($session)
            ->where('student_subject_enrollments.student_id', $studentId)
            ->lockForUpdate()
            ->first();
    }

    public function saveRecords(
        AttendanceSession $session,
        array $records,
        User $actor,
        ?string $correctionReason,
    ): AttendanceSession {
        return DB::transaction(function () use ($session, $records, $actor, $correctionReason): AttendanceSession {
            $lockedSession = AttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedSession->load('classSchedule.subjectOffering.section');

            if ($lockedSession->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'session' => 'Attendance cannot be recorded for a cancelled session.',
                ]);
            }

            $offeringId = $lockedSession->classSchedule->subject_offering_id;
            $eligibleIds = $this->eligibleEnrollments($lockedSession)
                ->pluck('student_subject_enrollments.id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

            foreach ($records as $index => $attributes) {
                $enrollmentId = (int) $attributes['student_subject_enrollment_id'];
                $enrollment = StudentSubjectEnrollment::query()
                    ->whereKey($enrollmentId)
                    ->where('subject_offering_id', $offeringId)
                    ->first();

                if ($enrollment === null) {
                    throw ValidationException::withMessages([
                        "records.{$index}.student_subject_enrollment_id" => 'The enrollment does not belong to this scheduled offering.',
                    ]);
                }

                $attendance = StudentAttendance::query()
                    ->where('attendance_session_id', $lockedSession->id)
                    ->where('student_subject_enrollment_id', $enrollmentId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedSession->status === 'submitted' && $attendance === null) {
                    throw ValidationException::withMessages([
                        "records.{$index}.student_subject_enrollment_id" => 'A submitted session cannot add new attendance records.',
                    ]);
                }

                if ($attendance === null && ! $eligibleIds->has($enrollmentId)) {
                    throw ValidationException::withMessages([
                        "records.{$index}.student_subject_enrollment_id" => 'The student was not actively enrolled for this class meeting.',
                    ]);
                }

                $checkInAt = array_key_exists('check_in_at', $attributes)
                    ? $attributes['check_in_at']
                    : $attendance?->check_in_at;
                $remarks = array_key_exists('remarks', $attributes)
                    ? $attributes['remarks']
                    : $attendance?->remarks;

                if ($attributes['status'] === 'excused' && trim((string) $remarks) === '') {
                    throw ValidationException::withMessages([
                        "records.{$index}.remarks" => 'An explanation is required for excused attendance.',
                    ]);
                }

                if ($attendance === null) {
                    StudentAttendance::create([
                        'attendance_session_id' => $lockedSession->id,
                        'student_subject_enrollment_id' => $enrollmentId,
                        'status' => $attributes['status'],
                        'check_in_at' => $checkInAt,
                        'remarks' => $remarks,
                        'recorded_by_user_id' => $actor->id,
                    ]);

                    continue;
                }

                $changed = $attendance->status !== $attributes['status']
                    || $this->timeValue($attendance->check_in_at) !== $this->timeValue($checkInAt)
                    || $attendance->remarks !== $remarks;

                if (! $changed) {
                    continue;
                }

                if ($lockedSession->status === 'submitted') {
                    if (trim((string) $correctionReason) === '') {
                        throw ValidationException::withMessages([
                            'correction_reason' => 'A reason is required to correct a submitted attendance record.',
                        ]);
                    }

                    $attendance->changes()->create([
                        'previous_status' => $attendance->status,
                        'new_status' => $attributes['status'],
                        'previous_check_in_at' => $attendance->check_in_at,
                        'new_check_in_at' => $checkInAt,
                        'previous_remarks' => $attendance->remarks,
                        'new_remarks' => $remarks,
                        'correction_reason' => trim($correctionReason),
                        'changed_by_user_id' => $actor->id,
                    ]);
                }

                $attendance->update([
                    'status' => $attributes['status'],
                    'check_in_at' => $checkInAt,
                    'remarks' => $remarks,
                    'updated_by_user_id' => $actor->id,
                ]);
            }

            return $lockedSession->fresh();
        }, attempts: 3);
    }

    public function updateStatus(
        AttendanceSession $session,
        string $status,
        User $actor,
        ?string $cancellationReason = null,
    ): AttendanceSession {
        return DB::transaction(function () use ($session, $status, $actor, $cancellationReason): AttendanceSession {
            $lockedSession = AttendanceSession::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($status === 'submitted') {
                if ($lockedSession->status !== 'open') {
                    throw ValidationException::withMessages([
                        'status' => 'Only open attendance sessions can be submitted.',
                    ]);
                }

                $lockedSession->load('classSchedule.subjectOffering');
                $eligibleIds = $this->eligibleEnrollments($lockedSession)
                    ->pluck('student_subject_enrollments.id')
                    ->map(fn ($id): int => (int) $id);
                $recordedIds = $lockedSession->records()
                    ->whereIn('student_subject_enrollment_id', $eligibleIds)
                    ->pluck('student_subject_enrollment_id')
                    ->map(fn ($id): int => (int) $id);

                if ($eligibleIds->isEmpty() || $eligibleIds->diff($recordedIds)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'status' => 'Record attendance for every eligible student before submitting this session.',
                    ]);
                }

                $lockedSession->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
                $this->closeQrPeriod($lockedSession);
            } else {
                if ($lockedSession->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        'status' => 'This attendance session is already cancelled.',
                    ]);
                }

                $lockedSession->update([
                    'status' => 'cancelled',
                    'cancelled_by_user_id' => $actor->id,
                    'cancelled_at' => now(),
                    'cancellation_reason' => trim((string) $cancellationReason),
                ]);
                $this->closeQrPeriod($lockedSession);
            }

            return $lockedSession->fresh();
        }, attempts: 3);
    }

    private function assertScheduleUsable(ClassSchedule $schedule, User $actor): void
    {
        if ($schedule->status !== 'published') {
            throw ValidationException::withMessages([
                'class_schedule_id' => 'Attendance sessions require a published class schedule.',
            ]);
        }

        $professor = $actor->professor;
        if (! $actor->isActive()
            || ! $actor->hasRole(Role::PROFESSOR)
            || $professor === null
            || $professor->status !== 'active'
            || ! $schedule->scheduleAssignments()
                ->where('status', 'active')
                ->whereHas('facultyAssignment', fn ($query) => $query
                    ->where('professor_id', $professor->id)
                    ->where('status', 'active'))
                ->exists()) {
            abort(403, 'Only the assigned Professor may create an attendance session.');
        }
    }

    private function closeQrPeriod(AttendanceSession $session): void
    {
        if ($session->qr_opened_at === null) {
            return;
        }

        $closedAt = now();

        $session->forceFill(['qr_closed_at' => $closedAt])->save();

        AttendanceQrToken::query()
            ->where('attendance_session_id', $session->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $closedAt]);
    }

    private function eligibleEnrollments(AttendanceSession $session): Builder
    {
        $session->loadMissing('classSchedule.subjectOffering');
        $schedule = $session->classSchedule;
        $classStartUtc = CarbonImmutable::parse(
            $session->class_date->toDateString().' '.(string) $schedule->start_time,
            config('app.timezone'),
        )->utc()->format('Y-m-d H:i:s');

        return StudentSubjectEnrollment::query()
            ->select('student_subject_enrollments.*')
            ->join('students', 'students.id', '=', 'student_subject_enrollments.student_id')
            ->where('student_subject_enrollments.subject_offering_id', $schedule->subject_offering_id)
            ->whereIn('student_subject_enrollments.status', ['enrolled', 'dropped', 'withdrawn'])
            ->where('student_subject_enrollments.enrolled_at', '<=', $classStartUtc)
            ->where(function (Builder $query) use ($classStartUtc): void {
                $query->whereNull('student_subject_enrollments.dropped_at')
                    ->orWhere('student_subject_enrollments.dropped_at', '>', $classStartUtc);
            })
            ->where('students.status', 'active')
            ->whereHas('student.user', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('code', Role::STUDENT)));
    }

    private function timeValue(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
