<?php

namespace App\Support;

use App\Models\AttendanceSession;
use App\Models\StudentAttendance;
use App\Models\StudentSubjectEnrollment;

final class AttendancePresenter
{
    public static function session(AttendanceSession $session): array
    {
        $schedule = $session->classSchedule;
        $offering = $schedule->subjectOffering;
        $section = $offering->section;
        $term = $offering->academicTerm;

        return [
            'id' => $session->id,
            'class_date' => $session->class_date?->toDateString(),
            'status' => $session->status,
            'created_by_user_id' => $session->created_by_user_id,
            'submitted_at' => $session->submitted_at?->toIso8601String(),
            'qr_opened_at' => $session->qr_opened_at?->toIso8601String(),
            'qr_closed_at' => $session->qr_closed_at?->toIso8601String(),
            'qr_active' => $session->qrIsActive(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $session->cancellation_reason,
            'schedule' => [
                'id' => $schedule->id,
                'day_of_week' => $schedule->day_of_week,
                'start_time' => substr((string) $schedule->start_time, 0, 5),
                'end_time' => substr((string) $schedule->end_time, 0, 5),
                'status' => $schedule->status,
            ],
            'offering' => [
                'id' => $offering->id,
                'subject' => [
                    'id' => $offering->subject->id,
                    'code' => $offering->subject->code,
                    'title' => $offering->subject->title,
                ],
                'section' => [
                    'id' => $section->id,
                    'code' => $section->code,
                    'name' => $section->name,
                    'program' => [
                        'id' => $section->program->id,
                        'code' => $section->program->code,
                        'name' => $section->program->name,
                    ],
                ],
                'academic_term' => [
                    'id' => $term->id,
                    'academic_year' => $term->academic_year,
                    'term' => $term->term,
                    'name' => $term->name,
                ],
            ],
            'professors' => $schedule->scheduleAssignments
                ->map(fn ($assignment): array => [
                    'id' => $assignment->facultyAssignment->professor->id,
                    'name' => $assignment->facultyAssignment->professor->user->name,
                ])
                ->unique('id')
                ->values()
                ->all(),
        ];
    }

    public static function record(StudentAttendance $attendance, bool $includeChanges = false): array
    {
        $enrollment = $attendance->enrollment;
        $student = $enrollment->student;

        $record = [
            'id' => $attendance->id,
            'student_subject_enrollment_id' => $attendance->student_subject_enrollment_id,
            'status' => $attendance->status,
            'check_in_at' => $attendance->check_in_at === null
                ? null
                : AttendanceClock::formatTime((string) $attendance->check_in_at),
            'remarks' => $attendance->remarks,
            'submission_source' => $attendance->submission_source,
            'recorded_by_user_id' => $attendance->recorded_by_user_id,
            'updated_by_user_id' => $attendance->updated_by_user_id,
            'student' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->user->name,
            ],
        ];

        if ($includeChanges) {
            $record['changes'] = $attendance->changes
                ->map(fn ($change): array => [
                    'previous_status' => $change->previous_status,
                    'new_status' => $change->new_status,
                    'previous_check_in_at' => $change->previous_check_in_at === null
                        ? null
                        : AttendanceClock::formatTime((string) $change->previous_check_in_at),
                    'new_check_in_at' => $change->new_check_in_at === null
                        ? null
                        : AttendanceClock::formatTime((string) $change->new_check_in_at),
                    'previous_remarks' => $change->previous_remarks,
                    'new_remarks' => $change->new_remarks,
                    'correction_reason' => $change->correction_reason,
                    'changed_by' => $change->changedBy?->name,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                ])->all();
        }

        return $record;
    }

    public static function rosterEntry(
        StudentSubjectEnrollment $enrollment,
        ?StudentAttendance $attendance,
        bool $includeChanges = false,
    ): array {
        return [
            'student_subject_enrollment_id' => $enrollment->id,
            'student' => [
                'id' => $enrollment->student->id,
                'student_number' => $enrollment->student->student_number,
                'name' => $enrollment->student->user->name,
            ],
            'attendance' => $attendance === null ? null : self::record($attendance, $includeChanges),
        ];
    }
}
