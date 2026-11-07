<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\QrAttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CheckInAttendanceRequest;
use App\Http\Requests\Attendance\IndexAttendanceRecordsRequest;
use App\Http\Requests\Attendance\IndexAttendanceSessionsRequest;
use App\Http\Requests\Attendance\IndexOwnAttendanceRequest;
use App\Http\Requests\Attendance\StoreAttendanceRecordsRequest;
use App\Http\Requests\Attendance\StoreAttendanceSessionRequest;
use App\Http\Requests\Attendance\UpdateAttendanceSessionStatusRequest;
use App\Models\AcademicTerm;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentAttendance;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Services\Attendance\AttendanceManagementService;
use App\Services\Attendance\QrAttendanceService;
use App\Support\ApiResponse;
use App\Support\AttendancePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceManagementService $attendance,
        private readonly QrAttendanceService $qrAttendance,
    ) {}

    public function options(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AttendanceSession::class);
        $actor = $request->user();
        $programIds = $this->programIdsFor($actor);

        $sections = Section::query()
            ->whereIn('program_id', $programIds)
            ->orderBy('code')
            ->get(['id', 'program_id', 'academic_term_id', 'code', 'name']);
        $terms = AcademicTerm::query()
            ->whereHas('sections', fn (Builder $query) => $query->whereIn('program_id', $programIds))
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->get(['id', 'academic_year', 'term', 'name']);
        $subjects = Subject::query()
            ->whereHas('subjectOfferings.section', fn (Builder $query) => $query->whereIn('program_id', $programIds))
            ->orderBy('code')
            ->get(['id', 'code', 'title']);
        $professors = Professor::query()
            ->whereHas('facultyAssignments.subjectOffering.section', fn (Builder $query) => $query
                ->whereIn('program_id', $programIds))
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'user_id', 'employee_number'])
            ->map(fn (Professor $professor): array => [
                'id' => $professor->id,
                'name' => $professor->user->name,
            ])
            ->values();

        return ApiResponse::success([
            'programs' => Program::query()
                ->whereIn('id', $programIds)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'terms' => $terms,
            'sections' => $sections,
            'subjects' => $subjects,
            'professors' => $professors,
        ], 'Attendance filters loaded.');
    }

    public function sessions(IndexAttendanceSessionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $actor = $request->user();
        $query = AttendanceSession::query()
            ->with(self::sessionRelations())
            ->withCount('records');

        if ($actor->hasRole('professor')) {
            $professorId = $actor->professor?->id;
            $query->whereHas('classSchedule.scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                ->where('professor_id', $professorId));
        } elseif ($actor->hasRole('department_personnel')) {
            $this->scopeSessionsToPrograms($query, $this->programIdsFor($actor));
        }

        $this->applySessionFilters($query, $filters);

        $sessions = $query
            ->orderByDesc('class_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'sessions' => collect($sessions->items())->map(fn (AttendanceSession $session): array => [
                ...AttendancePresenter::session($session),
                'record_count' => $session->records_count,
            ])->all(),
            'pagination' => self::pagination($sessions),
        ], 'Attendance sessions loaded.');
    }

    public function storeSession(StoreAttendanceSessionRequest $request): JsonResponse
    {
        $schedule = ClassSchedule::query()->findOrFail($request->validated('class_schedule_id'));
        Gate::authorize('create', [AttendanceSession::class, $schedule]);

        $session = $this->attendance->createSession(
            $schedule,
            $request->validated('class_date'),
            $request->user(),
        );
        $session->load(self::sessionRelations());

        return ApiResponse::success(
            ['session' => AttendancePresenter::session($session)],
            'Attendance session created.',
            201,
        );
    }

    public function qrStatus(AttendanceSession $session): JsonResponse
    {
        Gate::authorize('manageQr', $session);

        return ApiResponse::success(
            ['qr' => $this->qrAttendance->status($session)],
            'QR attendance status loaded.',
        );
    }

    public function startQr(Request $request, AttendanceSession $session): JsonResponse
    {
        Gate::authorize('manageQr', $session);

        return ApiResponse::success(
            $this->qrAttendance->start($session, $request->user()),
            'QR attendance started.',
        );
    }

    public function stopQr(Request $request, AttendanceSession $session): JsonResponse
    {
        Gate::authorize('manageQr', $session);

        return ApiResponse::success(
            $this->qrAttendance->stop($session),
            'QR attendance ended.',
        );
    }

    public function checkIn(CheckInAttendanceRequest $request): JsonResponse
    {
        try {
            $result = $this->qrAttendance->checkIn(
                $request->validated('token'),
                $request->user(),
            );
        } catch (QrAttendanceException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                $exception->status,
                null,
                $exception->reason,
            );
        }

        return ApiResponse::success($result, 'Attendance recorded.');
    }

    public function show(Request $request, AttendanceSession $session): JsonResponse
    {
        Gate::authorize('view', $session);
        $session->load(self::sessionRelations());

        $records = $session->records()
            ->with(self::recordRelations())
            ->orderBy('id')
            ->get();
        $recordsByEnrollment = $records->keyBy('student_subject_enrollment_id');
        $roster = $this->attendance->rosterForSession($session)
            ->map(function ($enrollment) use ($recordsByEnrollment, $request): array {
                $record = $recordsByEnrollment->get($enrollment->id);

                return AttendancePresenter::rosterEntry(
                    $enrollment,
                    $record,
                    $request->user()->hasRole('administrator', 'professor'),
                );
            });

        foreach ($records as $record) {
            if (! $roster->contains(
                fn (array $entry): bool => $entry['student_subject_enrollment_id'] === $record->student_subject_enrollment_id,
            )) {
                $roster->push(AttendancePresenter::rosterEntry(
                    $record->enrollment,
                    $record,
                    $request->user()->hasRole('administrator', 'professor'),
                ));
            }
        }

        return ApiResponse::success([
            'session' => AttendancePresenter::session($session),
            'roster' => $roster->values()->all(),
        ], 'Attendance session loaded.');
    }

    public function updateStatus(
        UpdateAttendanceSessionStatusRequest $request,
        AttendanceSession $session,
    ): JsonResponse {
        $updated = $this->attendance->updateStatus(
            $session,
            $request->validated('status'),
            $request->user(),
            $request->validated('cancellation_reason'),
        );
        $updated->load(self::sessionRelations());

        return ApiResponse::success(
            ['session' => AttendancePresenter::session($updated)],
            'Attendance session status updated.',
        );
    }

    public function storeRecords(
        StoreAttendanceRecordsRequest $request,
        AttendanceSession $session,
    ): JsonResponse {
        $updated = $this->attendance->saveRecords(
            $session,
            $request->validated('records'),
            $request->user(),
            $request->validated('correction_reason'),
        );
        $updated->load(self::sessionRelations());

        return ApiResponse::success(
            ['session' => AttendancePresenter::session($updated)],
            'Attendance records saved.',
        );
    }

    public function monitoring(IndexAttendanceRecordsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $actor = $request->user();
        $query = StudentAttendance::query()->with(self::recordRelations());

        if ($actor->hasRole('department_personnel')) {
            $this->scopeRecordsToPrograms($query, $this->programIdsFor($actor));
        }

        $this->applyRecordFilters($query, $filters);
        $summary = $this->summaryCounts(clone $query);
        $records = $query
            ->orderByDesc(
                AttendanceSession::query()
                    ->select('class_date')
                    ->whereColumn('attendance_sessions.id', 'student_attendance.attendance_session_id')
                    ->limit(1),
            )
            ->orderBy('student_attendance.id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'records' => collect($records->items())->map(fn (StudentAttendance $record): array => [
                'attendance' => AttendancePresenter::record($record),
                'session' => AttendancePresenter::session($record->attendanceSession),
            ])->all(),
            'summary' => $summary,
            'pagination' => self::pagination($records),
        ], 'Attendance records loaded.');
    }

    public function ownRecords(IndexOwnAttendanceRequest $request): JsonResponse
    {
        $studentId = $request->user()->student->id;
        $filters = $request->validated();
        $query = StudentAttendance::query()
            ->whereHas('enrollment', fn (Builder $enrollment) => $enrollment->where('student_id', $studentId))
            ->with(self::recordRelations());

        $this->applyOwnAttendanceFilters($query, $filters);
        $summary = $this->summaryCounts(clone $query);
        $records = $query
            ->orderByDesc(
                AttendanceSession::query()
                    ->select('class_date')
                    ->whereColumn('attendance_sessions.id', 'student_attendance.attendance_session_id')
                    ->limit(1),
            )
            ->orderByDesc('student_attendance.id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'records' => collect($records->items())->map(fn (StudentAttendance $record): array => [
                'attendance' => AttendancePresenter::record($record),
                'session' => AttendancePresenter::session($record->attendanceSession),
            ])->all(),
            'summary' => $summary,
            'pagination' => self::pagination($records),
        ], 'Your attendance records loaded.');
    }

    public function ownSummary(IndexOwnAttendanceRequest $request): JsonResponse
    {
        $studentId = $request->user()->student->id;
        $query = StudentAttendance::query()
            ->whereHas('enrollment', fn (Builder $enrollment) => $enrollment->where('student_id', $studentId));
        $this->applyOwnAttendanceFilters($query, $request->validated());
        $query->whereHas('attendanceSession', fn (Builder $session) => $session->where('status', '<>', 'cancelled'));
        $studentOfferingQuery = fn (Builder $offering) => $offering
            ->whereHas('enrollments', fn (Builder $enrollment) => $enrollment->where('student_id', $studentId))
            ->whereHas('classSchedules.attendanceSessions.records.enrollment', fn (Builder $enrollment) => $enrollment
                ->where('student_id', $studentId));
        $terms = AcademicTerm::query()
            ->whereHas('subjectOfferings', $studentOfferingQuery)
            ->orderByDesc('starts_on')
            ->get(['id', 'academic_year', 'term', 'name']);
        $offerings = SubjectOffering::query()
            ->where($studentOfferingQuery)
            ->with(['subject:id,code,title', 'academicTerm:id,academic_year,term,name'])
            ->orderBy('id')
            ->get()
            ->map(fn ($offering): array => [
                'id' => $offering->id,
                'subject' => [
                    'id' => $offering->subject->id,
                    'code' => $offering->subject->code,
                    'title' => $offering->subject->title,
                ],
                'academic_term_id' => $offering->academicTerm->id,
                'academic_term' => $offering->academicTerm->name,
            ]);

        $bySubject = (clone $query)
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'student_attendance.attendance_session_id')
            ->join('class_schedules', 'class_schedules.id', '=', 'attendance_sessions.class_schedule_id')
            ->join('subject_offerings', 'subject_offerings.id', '=', 'class_schedules.subject_offering_id')
            ->join('subjects', 'subjects.id', '=', 'subject_offerings.subject_id')
            ->select([
                'subjects.id',
                'subjects.code',
                'subjects.title',
                DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(student_attendance.status = 'present') AS present"),
                DB::raw("SUM(student_attendance.status = 'absent') AS absent"),
                DB::raw("SUM(student_attendance.status = 'late') AS late"),
                DB::raw("SUM(student_attendance.status = 'excused') AS excused"),
            ])
            ->groupBy('subjects.id', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->get();

        return ApiResponse::success([
            'summary' => $this->summaryCounts(clone $query),
            'by_subject' => $bySubject,
            'terms' => $terms,
            'offerings' => $offerings,
        ], 'Your attendance summary loaded.');
    }

    private function applySessionFilters(Builder $query, array $filters): void
    {
        foreach ([
            'academic_term_id' => ['classSchedule.subjectOffering', 'academic_term_id'],
            'program_id' => ['classSchedule.subjectOffering.section', 'program_id'],
            'section_id' => ['classSchedule.subjectOffering', 'section_id'],
            'subject_id' => ['classSchedule.subjectOffering', 'subject_id'],
        ] as $filter => [$relation, $column]) {
            if (isset($filters[$filter])) {
                $query->whereHas($relation, fn (Builder $related) => $related->where($column, $filters[$filter]));
            }
        }

        if (isset($filters['professor_id'])) {
            $query->whereHas('classSchedule.scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                ->where('professor_id', $filters['professor_id']));
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['from_date'])) {
            $query->whereDate('class_date', '>=', $filters['from_date']);
        }
        if (isset($filters['to_date'])) {
            $query->whereDate('class_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->whereHas('classSchedule.subjectOffering', fn (Builder $offering) => $offering
                ->whereHas('subject', fn (Builder $subject) => $subject
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%'))
                ->orWhereHas('section', fn (Builder $section) => $section
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')));
        }
    }

    private function applyRecordFilters(Builder $query, array $filters): void
    {
        if (isset($filters['academic_term_id'])) {
            $query->whereHas('attendanceSession.classSchedule.subjectOffering', fn (Builder $offering) => $offering
                ->where('academic_term_id', $filters['academic_term_id']));
        }
        if (isset($filters['program_id'])) {
            $query->whereHas('attendanceSession.classSchedule.subjectOffering.section', fn (Builder $section) => $section
                ->where('program_id', $filters['program_id']));
        }
        if (isset($filters['section_id'])) {
            $query->whereHas('attendanceSession.classSchedule.subjectOffering.section', fn (Builder $section) => $section
                ->whereKey($filters['section_id']));
        }
        if (isset($filters['subject_id'])) {
            $query->whereHas('attendanceSession.classSchedule.subjectOffering', fn (Builder $offering) => $offering
                ->where('subject_id', $filters['subject_id']));
        }
        if (isset($filters['professor_id'])) {
            $query->whereHas('attendanceSession.classSchedule.scheduleAssignments.facultyAssignment', fn (Builder $faculty) => $faculty
                ->where('professor_id', $filters['professor_id']));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['from_date'])) {
            $query->whereHas('attendanceSession', fn (Builder $session) => $session
                ->whereDate('class_date', '>=', $filters['from_date']));
        }
        if (isset($filters['to_date'])) {
            $query->whereHas('attendanceSession', fn (Builder $session) => $session
                ->whereDate('class_date', '<=', $filters['to_date']));
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $record) use ($search): void {
                $record->whereHas('enrollment.student', fn (Builder $student) => $student
                    ->where('student_number', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%')))
                    ->orWhereHas('attendanceSession.classSchedule.subjectOffering.subject', fn (Builder $subject) => $subject
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%'));
            });
        }
    }

    private function applyOwnAttendanceFilters(Builder $query, array $filters): void
    {
        if (isset($filters['academic_term_id'])) {
            $query->whereHas('attendanceSession.classSchedule.subjectOffering', fn (Builder $offering) => $offering
                ->where('academic_term_id', $filters['academic_term_id']));
        }
        if (isset($filters['subject_offering_id'])) {
            $query->whereHas('attendanceSession.classSchedule', fn (Builder $schedule) => $schedule
                ->where('subject_offering_id', $filters['subject_offering_id']));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['from_date'])) {
            $query->whereHas('attendanceSession', fn (Builder $session) => $session
                ->whereDate('class_date', '>=', $filters['from_date']));
        }
        if (isset($filters['to_date'])) {
            $query->whereHas('attendanceSession', fn (Builder $session) => $session
                ->whereDate('class_date', '<=', $filters['to_date']));
        }
    }

    private function scopeSessionsToPrograms(Builder $query, Collection $programIds): void
    {
        $query->whereHas('classSchedule.subjectOffering.section', fn (Builder $section) => $section
            ->whereIn('program_id', $programIds));
    }

    private function scopeRecordsToPrograms(Builder $query, Collection $programIds): void
    {
        $query->whereHas('attendanceSession.classSchedule.subjectOffering.section', fn (Builder $section) => $section
            ->whereIn('program_id', $programIds));
    }

    private function programIdsFor(User $user): Collection
    {
        if ($user->hasRole('department_personnel')) {
            return $user->departmentPersonnelPrograms()->pluck('programs.id');
        }

        return Program::query()->pluck('id');
    }

    private function summaryCounts(Builder $query): array
    {
        $counts = $query
            ->whereHas('attendanceSession', fn (Builder $session) => $session->where('status', '<>', 'cancelled'))
            ->select('student_attendance.status', DB::raw('COUNT(*) AS total'))
            ->groupBy('student_attendance.status')
            ->pluck('total', 'status');

        return [
            'present' => (int) ($counts['present'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'excused' => (int) ($counts['excused'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    private static function sessionRelations(): array
    {
        return [
            'classSchedule.subjectOffering.subject',
            'classSchedule.subjectOffering.section.program',
            'classSchedule.subjectOffering.academicTerm',
            'classSchedule.scheduleAssignments.facultyAssignment.professor.user',
        ];
    }

    private static function recordRelations(): array
    {
        return [
            'enrollment.student.user:id,name,status',
            'attendanceSession.classSchedule.subjectOffering.subject',
            'attendanceSession.classSchedule.subjectOffering.section.program',
            'attendanceSession.classSchedule.subjectOffering.academicTerm',
            'attendanceSession.classSchedule.scheduleAssignments.facultyAssignment.professor.user',
            'changes.changedBy:id,name',
        ];
    }

    private static function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
