<?php

namespace App\Services\Attendance;

use App\Exceptions\QrAttendanceException;
use App\Models\AttendanceQrToken;
use App\Models\AttendanceSession;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Support\AttendanceClock;
use App\Support\AttendancePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QrAttendanceService
{
    public function __construct(private readonly AttendanceManagementService $attendance) {}

    public function status(AttendanceSession $session): array
    {
        $session->loadMissing('classSchedule.subjectOffering');
        $latestToken = $session->qrTokens()
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        return [
            'active' => $session->qrIsActive() && $session->status === 'open',
            'opened_at' => $session->qr_opened_at?->toIso8601String(),
            'closed_at' => $session->qr_closed_at?->toIso8601String(),
            'expires_at' => $latestToken?->expires_at?->toIso8601String(),
            'token_ttl_seconds' => $this->tokenTtl(),
            'monitoring' => $this->monitoring($session),
        ];
    }

    public function start(AttendanceSession $session, User $actor): array
    {
        [$token, $lockedSession] = DB::transaction(function () use ($session, $actor): array {
            $lockedSession = AttendanceSession::query()
                ->lockForUpdate()
                ->findOrFail($session->id);
            $lockedSession->load('classSchedule.subjectOffering');

            $this->assertQrUsable($lockedSession);

            $now = CarbonImmutable::now();
            $reopening = ! $lockedSession->qrIsActive();

            AttendanceQrToken::query()
                ->where('attendance_session_id', $lockedSession->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            $token = $this->generateToken();

            AttendanceQrToken::create([
                'attendance_session_id' => $lockedSession->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->addSeconds($this->tokenTtl()),
                'created_by_user_id' => $actor->id,
            ]);

            $lockedSession->forceFill([
                'qr_opened_at' => $reopening ? $now : $lockedSession->qr_opened_at,
                'qr_closed_at' => null,
            ])->save();

            return [$token, $lockedSession->fresh()];
        }, attempts: 3);

        return [
            'token' => $token,
            'qr' => $this->status($lockedSession),
        ];
    }

    public function stop(AttendanceSession $session): array
    {
        $lockedSession = DB::transaction(function () use ($session): AttendanceSession {
            $lockedSession = AttendanceSession::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($lockedSession->qr_opened_at === null) {
                return $lockedSession;
            }

            $closedAt = CarbonImmutable::now();

            $lockedSession->forceFill(['qr_closed_at' => $closedAt])->save();

            AttendanceQrToken::query()
                ->where('attendance_session_id', $lockedSession->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $closedAt]);

            return $lockedSession->fresh();
        }, attempts: 3);

        return ['qr' => $this->status($lockedSession)];
    }

    public function checkIn(string $token, User $studentUser): array
    {
        try {
            $record = DB::transaction(function () use ($token, $studentUser): StudentAttendance {
                $tokenHash = hash('sha256', $token);
                $qrToken = AttendanceQrToken::query()
                    ->where('token_hash', $tokenHash)
                    ->first();

                if ($qrToken === null) {
                    throw QrAttendanceException::invalidToken();
                }

                $session = AttendanceSession::query()
                    ->lockForUpdate()
                    ->findOrFail($qrToken->attendance_session_id);
                $session->load('classSchedule.subjectOffering');

                $qrToken = AttendanceQrToken::query()
                    ->whereKey($qrToken->id)
                    ->lockForUpdate()
                    ->first();

                if ($qrToken === null) {
                    throw QrAttendanceException::invalidToken();
                }

                if ($session->status === 'cancelled') {
                    throw QrAttendanceException::sessionCancelled();
                }

                if ($session->status !== 'open') {
                    throw QrAttendanceException::sessionClosed();
                }

                if ($session->qr_opened_at === null) {
                    throw QrAttendanceException::qrInactive();
                }

                if ($session->qr_closed_at !== null) {
                    throw QrAttendanceException::sessionClosed();
                }

                if ($session->classSchedule->status !== 'published') {
                    throw QrAttendanceException::sessionClosed();
                }

                if ($qrToken->revoked_at !== null) {
                    throw QrAttendanceException::invalidToken();
                }

                if ($qrToken->expires_at === null || $qrToken->expires_at->lte(CarbonImmutable::now())) {
                    throw QrAttendanceException::expiredToken();
                }

                $student = $studentUser->student;

                if ($student === null) {
                    throw QrAttendanceException::notEnrolled();
                }

                $enrollment = $this->attendance->eligibleEnrollmentForStudent($session, $student->id);

                if ($enrollment === null) {
                    throw QrAttendanceException::notEnrolled();
                }

                $existing = StudentAttendance::query()
                    ->where('attendance_session_id', $session->id)
                    ->where('student_subject_enrollment_id', $enrollment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw QrAttendanceException::alreadyRecorded();
                }

                $now = CarbonImmutable::now();

                $record = StudentAttendance::create([
                    'attendance_session_id' => $session->id,
                    'student_subject_enrollment_id' => $enrollment->id,
                    'status' => $this->statusForCheckIn($session, $now),
                    'check_in_at' => AttendanceClock::checkInTime($now),
                    'remarks' => null,
                    'submission_source' => StudentAttendance::SOURCE_QR,
                    'recorded_by_user_id' => $studentUser->id,
                ]);

                return $record->load([
                    'enrollment.student.user:id,name,status',
                    'attendanceSession.classSchedule.subjectOffering.subject',
                    'attendanceSession.classSchedule.subjectOffering.section.program',
                    'attendanceSession.classSchedule.subjectOffering.academicTerm',
                    'attendanceSession.classSchedule.scheduleAssignments.facultyAssignment.professor.user',
                    'changes.changedBy:id,name',
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw QrAttendanceException::alreadyRecorded();
        }

        return [
            'attendance' => AttendancePresenter::record($record),
            'session' => AttendancePresenter::session($record->attendanceSession),
        ];
    }

    private function monitoring(AttendanceSession $session): array
    {
        $enrollments = $this->attendance->eligibleEnrollmentsForSession($session)
            ->with(['student.user:id,name'])
            ->orderBy('students.student_number')
            ->get();

        $records = StudentAttendance::query()
            ->where('attendance_session_id', $session->id)
            ->whereIn('student_subject_enrollment_id', $enrollments->pluck('id'))
            ->whereIn('status', ['present', 'late'])
            ->get()
            ->keyBy('student_subject_enrollment_id');

        $checkedIn = $enrollments->filter(
            fn ($enrollment): bool => $records->has($enrollment->id),
        );
        $total = $enrollments->count();
        $checkedCount = $checkedIn->count();

        return [
            'total_enrolled' => $total,
            'checked_in' => $checkedCount,
            'not_checked_in' => max(0, $total - $checkedCount),
            'percentage' => $total > 0 ? round(($checkedCount / $total) * 100, 1) : 0.0,
            'checked_in_students' => $checkedIn->map(function ($enrollment) use ($records): array {
                $record = $records->get($enrollment->id);

                return [
                    'student_subject_enrollment_id' => $enrollment->id,
                    'student' => [
                        'id' => $enrollment->student->id,
                        'student_number' => $enrollment->student->student_number,
                        'name' => $enrollment->student->user->name,
                    ],
                    'status' => $record->status,
                    'check_in_at' => AttendanceClock::formatTime(
                        $record->check_in_at === null ? null : (string) $record->check_in_at,
                    ),
                    'submission_source' => $record->submission_source,
                ];
            })->values()->all(),
        ];
    }

    private function assertQrUsable(AttendanceSession $session): void
    {
        if ($session->status === 'cancelled') {
            throw ValidationException::withMessages([
                'session' => 'QR attendance cannot be started for a cancelled session.',
            ]);
        }

        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'session' => 'Only open attendance sessions can use QR check-in.',
            ]);
        }

        if ($session->classSchedule->status !== 'published') {
            throw ValidationException::withMessages([
                'session' => 'QR attendance requires a published class schedule.',
            ]);
        }
    }

    private function statusForCheckIn(
        AttendanceSession $session,
        CarbonImmutable $now,
    ): string {
        return AttendanceClock::statusForCheckIn($session, $now);
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenTtl(): int
    {
        return max(10, (int) config('attendance.qr_token_ttl_seconds', 30));
    }
}
