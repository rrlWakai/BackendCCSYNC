<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceExcuseRequest;
use App\Models\AttendanceExcuseRequestChange;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSession;
use App\Models\Role;
use App\Models\StudentAttendance;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Services\Attendance\AttendanceManagementService;
use App\Services\Attendance\AttendanceLedgerService;
use App\Services\Attendance\AttendanceLedgerXlsxExport;
use App\Support\ApiResponse;
use App\Support\AttendancePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AttendanceWorkflowController extends Controller
{
    public function __construct(
        private readonly AttendanceManagementService $attendance,
        private readonly AttendanceLedgerService $ledger,
    ) {}

    public function ledger(Request $request, SubjectOffering $offering): JsonResponse
    {
        $actor = $request->user();

        if ($actor->hasRole(Role::STUDENT)) {
            $studentId = $actor->student?->id;
            abort_unless($studentId !== null && $offering->enrollments()
                ->where('student_id', $studentId)
                ->exists(), 403);
            $result = $this->ledger->forOffering($offering, $studentId);
        } else {
            abort_unless($actor->hasRole(Role::ADMINISTRATOR)
                || ($actor->hasRole(Role::PROFESSOR)
                    && $offering->classSchedules()
                        ->whereHas('scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                            ->where('professor_id', $actor->professor?->id))
                        ->exists())
                || ($actor->hasRole(Role::DEPARTMENT_PERSONNEL)
                    && $actor->departmentPersonnelPrograms()
                        ->whereKey($offering->section->program_id)
                        ->exists()), 403);
            $result = $this->ledger->forOffering($offering);
        }

        return ApiResponse::success($result, 'Class assignment attendance ledger loaded.');
    }

    public function exportLedger(
        Request $request,
        SubjectOffering $offering,
        AttendanceLedgerXlsxExport $export,
    ) {
        $actor = $request->user();
        abort_unless($actor->hasRole(Role::PROFESSOR)
            && $offering->classSchedules()
                ->whereHas('scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                    ->where('professor_id', $actor->professor?->id))
                ->exists(), 403);

        $ledger = $this->ledger->forOffering($offering);
        $subject = $ledger['offering']['subject'];
        $section = $ledger['offering']['section'];
        $rows = [[
            'Student number',
            'Student name',
            'Subject',
            'Section',
            'Present',
            'Late',
            'Absent',
            'Excused',
            'Absence-equivalents',
            'Allowed absences',
            'Remaining absences',
            'Attendance percentage',
            'Weighted contribution / 20',
        ]];

        foreach ($ledger['students'] as $student) {
            $rows[] = [
                $student['student']['student_number'],
                $student['student']['name'],
                $subject['code'],
                $section['code'],
                $student['present'],
                $student['late'],
                $student['absent'],
                $student['excused'],
                $student['absence_equivalents'],
                $student['allowed_absences'],
                $student['remaining_absences'],
                $student['attendance_percentage'],
                $student['weighted_contribution'],
            ];
        }

        $path = $export->create($rows);
        $filename = sprintf(
            'attendance-%s-%s-%s.xlsx',
            preg_replace('/[^A-Za-z0-9_-]+/', '-', $subject['code']),
            preg_replace('/[^A-Za-z0-9_-]+/', '-', $section['code']),
            now()->format('Ymd'),
        );

        return response()->download(
            $path,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function myExcuses(Request $request): JsonResponse
    {
        $studentId = $request->user()->student->id;
        $requests = AttendanceExcuseRequest::query()
            ->whereHas('enrollment', fn (Builder $query) => $query->where('student_id', $studentId))
            ->with(['attendanceSession.classSchedule.subjectOffering.subject',
                'attendanceSession.classSchedule.subjectOffering.section',
                'enrollment.student.user:id,name',
                'changes.changedBy:id,name'])
            ->latest('id')
            ->paginate(25);

        return ApiResponse::success([
            'requests' => collect($requests->items())->map(self::excusePresenter(...))->all(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ], 'Your excuse requests loaded.');
    }

    public function requestExcuse(Request $request, AttendanceSession $session): JsonResponse
    {
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        $student = $request->user()->student;
        abort_unless($student !== null, 403);

        $excuse = DB::transaction(function () use ($request, $session, $student, $attributes): AttendanceExcuseRequest {
            $lockedSession = AttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($lockedSession->status === 'cancelled') {
                throw ValidationException::withMessages(['session' => 'A cancelled class cannot have an excuse request.']);
            }

            $enrollment = $this->attendance->eligibleEnrollmentForStudent($lockedSession, $student->id);
            if ($enrollment === null) {
                abort(403, 'You are not eligible for this scheduled meeting.');
            }

            $existingRequest = AttendanceExcuseRequest::query()
                ->where('attendance_session_id', $lockedSession->id)
                ->where('student_subject_enrollment_id', $enrollment->id)
                ->exists();
            if ($existingRequest) {
                throw ValidationException::withMessages([
                    'session' => 'An excuse request has already been submitted for this meeting.',
                ]);
            }

            $existing = StudentAttendance::query()
                ->where('attendance_session_id', $lockedSession->id)
                ->where('student_subject_enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && in_array($existing->status, ['present', 'late', 'excused'], true)) {
                throw ValidationException::withMessages([
                    'session' => 'An excuse request is available only for an absent or unrecorded meeting.',
                ]);
            }

            $created = AttendanceExcuseRequest::create([
                'attendance_session_id' => $lockedSession->id,
                'student_subject_enrollment_id' => $enrollment->id,
                'requested_by_user_id' => $request->user()->id,
                'reason' => trim($attributes['reason']),
                'status' => 'pending',
            ]);
            $created->changes()->create([
                'previous_status' => null,
                'new_status' => 'pending',
                'reason' => trim($attributes['reason']),
                'changed_by_user_id' => $request->user()->id,
            ]);

            return $created;
        });

        return ApiResponse::success(
            ['request' => self::excusePresenter($excuse->load([
                'attendanceSession.classSchedule.subjectOffering.subject',
                'attendanceSession.classSchedule.subjectOffering.section',
                'changes.changedBy:id,name',
            ]))],
            'Excuse request submitted.',
            201,
        );
    }

    public function offeringExcuses(Request $request, SubjectOffering $offering): JsonResponse
    {
        abort_unless($request->user()->hasRole(Role::PROFESSOR)
            && $offering->classSchedules()
                ->whereHas('scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                    ->where('professor_id', $request->user()->professor?->id))
                ->exists(), 403);

        $requests = AttendanceExcuseRequest::query()
            ->whereHas('attendanceSession.classSchedule', fn (Builder $schedule) =>
                $schedule->where('subject_offering_id', $offering->id))
            ->with([
                'attendanceSession.classSchedule.subjectOffering.subject',
                'attendanceSession.classSchedule.subjectOffering.section',
                'enrollment.student.user:id,name',
                'changes.changedBy:id,name',
            ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(50);

        return ApiResponse::success([
            'requests' => collect($requests->items())->map(self::excusePresenter(...))->all(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ], 'Class assignment excuse requests loaded.');
    }

    public function decideExcuse(Request $request, AttendanceExcuseRequest $excuse): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'decision_reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        $excuse->load('attendanceSession.classSchedule');
        Gate::authorize('manageQr', $excuse->attendanceSession);

        $updated = DB::transaction(function () use ($excuse, $request, $attributes): AttendanceExcuseRequest {
            $session = AttendanceSession::query()
                ->lockForUpdate()
                ->findOrFail($excuse->attendance_session_id);
            $locked = AttendanceExcuseRequest::query()
                ->lockForUpdate()
                ->findOrFail($excuse->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }
            if ($session->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'A cancelled meeting cannot have an excuse decision.']);
            }

            if ($attributes['status'] === 'approved') {
                $record = StudentAttendance::query()
                    ->where('attendance_session_id', $session->id)
                    ->where('student_subject_enrollment_id', $locked->student_subject_enrollment_id)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && ! in_array($record->status, ['absent', 'excused'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'An existing Present or Late record cannot be excused.',
                    ]);
                }

                $remarks = 'Excuse request approved: '.$locked->reason;
                if ($record === null) {
                    StudentAttendance::create([
                        'attendance_session_id' => $session->id,
                        'student_subject_enrollment_id' => $locked->student_subject_enrollment_id,
                        'status' => 'excused',
                        'remarks' => $remarks,
                        'recorded_by_user_id' => $request->user()->id,
                        'submission_source' => StudentAttendance::SOURCE_MANUAL,
                    ]);
                } elseif ($record->status === 'absent') {
                    if ($session->status === 'submitted') {
                        $record->changes()->create([
                            'previous_status' => $record->status,
                            'new_status' => 'excused',
                            'previous_check_in_at' => $record->check_in_at,
                            'new_check_in_at' => $record->check_in_at,
                            'previous_remarks' => $record->remarks,
                            'new_remarks' => $remarks,
                            'correction_reason' => trim($attributes['decision_reason']),
                            'changed_by_user_id' => $request->user()->id,
                        ]);
                    }

                    $record->update([
                        'status' => 'excused',
                        'remarks' => $remarks,
                        'updated_by_user_id' => $request->user()->id,
                    ]);
                }
            }

            $locked->update([
                'status' => $attributes['status'],
                'decided_by_user_id' => $request->user()->id,
                'decision_reason' => trim($attributes['decision_reason']),
                'decided_at' => now(),
            ]);
            $locked->changes()->create([
                'previous_status' => 'pending',
                'new_status' => $attributes['status'],
                'reason' => trim($attributes['decision_reason']),
                'changed_by_user_id' => $request->user()->id,
            ]);

            return $locked->fresh()->load([
                'attendanceSession.classSchedule.subjectOffering.subject',
                'attendanceSession.classSchedule.subjectOffering.section',
                'enrollment.student.user:id,name',
                'changes.changedBy:id,name',
            ]);
        }, attempts: 3);

        return ApiResponse::success(
            ['request' => self::excusePresenter($updated)],
            'Excuse request decision saved.',
        );
    }

    public function policies(Request $request): JsonResponse
    {
        $termId = $request->integer('academic_term_id');
        $query = AttendancePolicy::query()
            ->with(['academicTerm:id,academic_year,term,name', 'subjectOffering.subject:id,code,title',
                'subjectOffering.section:id,code,name', 'createdBy:id,name'])
            ->when($termId, fn (Builder $builder) => $builder->where('academic_term_id', $termId))
            ->orderByDesc('effective_at');

        return ApiResponse::success(['policies' => $query->get()], 'Attendance policies loaded.');
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'subject_offering_id' => ['nullable', 'integer', 'exists:subject_offerings,id'],
            'allowed_absences' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        if (isset($attributes['subject_offering_id'])
            && ! SubjectOffering::query()
                ->whereKey($attributes['subject_offering_id'])
                ->where('academic_term_id', $attributes['academic_term_id'])
                ->exists()) {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'The class assignment must belong to the selected academic term.',
            ]);
        }

        $policy = AttendancePolicy::create([
            ...$attributes,
            'effective_at' => now(),
            'created_by_user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(
            ['policy' => $policy->load(['academicTerm', 'subjectOffering.subject', 'subjectOffering.section', 'createdBy'])],
            'Attendance policy version created.',
            201,
        );
    }

    private static function excusePresenter(AttendanceExcuseRequest $excuse): array
    {
        $session = $excuse->attendanceSession;
        $offering = $session?->classSchedule?->subjectOffering;

        return [
            'id' => $excuse->id,
            'attendance_session_id' => $excuse->attendance_session_id,
            'student_subject_enrollment_id' => $excuse->student_subject_enrollment_id,
            'student' => $excuse->enrollment?->student ? [
                'id' => $excuse->enrollment->student->id,
                'student_number' => $excuse->enrollment->student->student_number,
                'name' => $excuse->enrollment->student->user?->name,
            ] : null,
            'session' => $session ? AttendancePresenter::session($session) : null,
            'subject' => $offering ? [
                'code' => $offering->subject->code,
                'title' => $offering->subject->title,
                'section' => $offering->section->code,
            ] : null,
            'reason' => $excuse->reason,
            'attachment_path' => $excuse->attachment_path,
            'status' => $excuse->status,
            'decision_reason' => $excuse->decision_reason,
            'decided_at' => $excuse->decided_at?->toIso8601String(),
            'changes' => $excuse->changes->map(fn (AttendanceExcuseRequestChange $change): array => [
                'previous_status' => $change->previous_status,
                'new_status' => $change->new_status,
                'reason' => $change->reason,
                'changed_by' => $change->changedBy?->name,
                'changed_at' => $change->changed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
