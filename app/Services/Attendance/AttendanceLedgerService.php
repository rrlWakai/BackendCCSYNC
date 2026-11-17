<?php

namespace App\Services\Attendance;

use App\Models\AttendancePolicy;
use App\Models\AttendanceSession;
use App\Models\StudentAttendance;
use App\Models\StudentSubjectEnrollment;
use App\Models\SubjectOffering;
use App\Support\AttendanceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AttendanceLedgerService
{
    public function forOffering(SubjectOffering $offering, ?int $studentId = null): array
    {
        $offering->loadMissing(['academicTerm', 'subject', 'section']);
        $term = $offering->academicTerm;
        $today = AttendanceClock::today();
        $schedules = $offering->classSchedules()
            ->where('status', 'published')
            ->with(['attendanceSessions.records'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $meetings = collect();
        foreach ($schedules as $schedule) {
            $date = CarbonImmutable::parse($term->starts_on->toDateString(), AttendanceClock::timezone())
                ->startOfDay();
            $end = CarbonImmutable::parse($term->ends_on->toDateString(), AttendanceClock::timezone())
                ->startOfDay()
                ->min($today);

            for (; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                if ($date->dayOfWeekIso !== (int) $schedule->day_of_week) {
                    continue;
                }

                $session = $schedule->attendanceSessions
                    ->first(fn (AttendanceSession $candidate): bool =>
                        $candidate->class_date?->toDateString() === $date->toDateString());
                if ($session?->status === 'cancelled') {
                    continue;
                }

                $meetings->push([
                    'date' => $date->toDateString(),
                    'schedule' => $schedule,
                    'session' => $session,
                    'state' => $session === null ? 'incomplete' : match ($session->status) {
                        'submitted' => 'held',
                        default => 'in_progress',
                    },
                ]);
            }
        }

        $enrollments = StudentSubjectEnrollment::query()
            ->with(['student.user:id,name'])
            ->where('subject_offering_id', $offering->id)
            ->whereIn('status', ['enrolled', 'dropped', 'withdrawn'])
            ->when($studentId !== null, fn ($query) => $query->where('student_id', $studentId))
            ->orderBy('student_id')
            ->get();

        $held = $meetings->where('state', 'held')->values();
        $policy = $this->policyFor($offering);
        $students = $enrollments
            ->map(fn (StudentSubjectEnrollment $enrollment): array =>
                $this->studentSummary($enrollment, $held, $policy))
            ->values();

        return [
            'offering' => [
                'id' => $offering->id,
                'subject' => ['id' => $offering->subject->id, 'code' => $offering->subject->code, 'title' => $offering->subject->title],
                'section' => ['id' => $offering->section->id, 'code' => $offering->section->code, 'name' => $offering->section->name],
                'academic_term' => [
                    'id' => $term->id,
                    'academic_year' => $term->academic_year,
                    'name' => $term->name,
                    'starts_on' => $term->starts_on->toDateString(),
                    'ends_on' => $term->ends_on->toDateString(),
                ],
            ],
            'held_meetings' => $held->count(),
            'incomplete_meetings' => $meetings->where('state', 'incomplete')->map(fn (array $meeting): array => [
                'date' => $meeting['date'],
                'schedule_id' => $meeting['schedule']->id,
                'start_time' => substr((string) $meeting['schedule']->start_time, 0, 5),
                'end_time' => substr((string) $meeting['schedule']->end_time, 0, 5),
            ])->values()->all(),
            'meetings' => $meetings->map(fn (array $meeting): array => [
                'date' => $meeting['date'],
                'schedule_id' => $meeting['schedule']->id,
                'session_id' => $meeting['session']?->id,
                'state' => $meeting['state'],
                'start_time' => substr((string) $meeting['schedule']->start_time, 0, 5),
                'end_time' => substr((string) $meeting['schedule']->end_time, 0, 5),
            ])->values()->all(),
            'policy' => $policy === null ? null : [
                'allowed_absences' => $policy->allowed_absences,
                'effective_at' => $policy->effective_at->toIso8601String(),
                'scope' => $policy->subject_offering_id === null ? 'institutional' : 'offering',
            ],
            'students' => $students->all(),
        ];
    }

    public function policyFor(SubjectOffering $offering): ?AttendancePolicy
    {
        $offering->loadMissing('academicTerm');
        $termEnd = CarbonImmutable::parse(
            $offering->academicTerm->ends_on->toDateString(),
            AttendanceClock::timezone(),
        )->endOfDay();
        $at = CarbonImmutable::now()->min($termEnd);
        $offeringPolicy = AttendancePolicy::query()
            ->where('academic_term_id', $offering->academic_term_id)
            ->where('subject_offering_id', $offering->id)
            ->where('effective_at', '<=', $at)
            ->latest('effective_at')
            ->first();

        return $offeringPolicy ?? AttendancePolicy::query()
            ->where('academic_term_id', $offering->academic_term_id)
            ->whereNull('subject_offering_id')
            ->where('effective_at', '<=', $at)
            ->latest('effective_at')
            ->first();
    }

    private function studentSummary(
        StudentSubjectEnrollment $enrollment,
        Collection $held,
        ?AttendancePolicy $policy,
    ): array {
        $eligibleMeetings = $held->filter(fn (array $meeting): bool =>
            $this->enrollmentCovers($enrollment, $meeting['date'], $meeting['schedule']->start_time));
        $records = $eligibleMeetings->map(function (array $meeting) use ($enrollment): ?StudentAttendance {
            return $meeting['session']->records
                ->firstWhere('student_subject_enrollment_id', $enrollment->id);
        });

        $present = $records->filter(fn ($record): bool => $record?->status === 'present')->count();
        $late = $records->filter(fn ($record): bool => $record?->status === 'late')->count();
        $absent = $records->filter(fn ($record): bool => $record?->status === 'absent')->count();
        $excused = $records->filter(fn ($record): bool => $record?->status === 'excused')->count();
        $eligibleCount = max(0, $eligibleMeetings->count() - $excused);
        $lateEquivalents = intdiv($late, 3);
        $absenceEquivalents = $absent + $lateEquivalents;
        $percentage = $eligibleCount === 0
            ? null
            : (($present + $late - $lateEquivalents) / $eligibleCount) * 100;
        $allowed = $policy?->allowed_absences;

        return [
            'student' => [
                'id' => $enrollment->student->id,
                'student_subject_enrollment_id' => $enrollment->id,
                'student_number' => $enrollment->student->student_number,
                'name' => $enrollment->student->user->name,
            ],
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excused' => $excused,
            'late_absence_equivalents' => $lateEquivalents,
            'absence_equivalents' => $absenceEquivalents,
            'eligible_meetings' => $eligibleCount,
            'attendance_percentage' => $percentage === null ? null : round($percentage, 2),
            'weighted_contribution' => $percentage === null ? null : round(($percentage / 100) * 20, 2),
            'allowed_absences' => $allowed,
            'remaining_absences' => $allowed === null ? null : $allowed - $absenceEquivalents,
            'allowance_exceeded' => $allowed !== null && $absenceEquivalents > $allowed,
        ];
    }

    private function enrollmentCovers(
        StudentSubjectEnrollment $enrollment,
        string $date,
        string $startTime,
    ): bool {
        if (! in_array($enrollment->status, ['enrolled', 'dropped', 'withdrawn'], true)) {
            return false;
        }
        if ($enrollment->status === 'withdrawn' && $enrollment->withdrawn_at === null) {
            return false;
        }
        if ($enrollment->status === 'dropped' && $enrollment->dropped_at === null) {
            return false;
        }

        $classStart = CarbonImmutable::parse(
            $date.' '.(string) $startTime,
            AttendanceClock::timezone(),
        );
        $enrolledAt = $enrollment->enrolled_at;
        if ($enrolledAt === null || $enrolledAt->greaterThan($classStart)) {
            return false;
        }

        $endedAt = $enrollment->status === 'withdrawn'
            ? $enrollment->withdrawn_at
            : $enrollment->dropped_at;

        return $endedAt === null || $endedAt->greaterThan($classStart);
    }
}
