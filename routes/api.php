<?php

use App\Http\Controllers\Api\V1\AcademicOptionsController;
use App\Http\Controllers\Api\V1\AcademicProgramController;
use App\Http\Controllers\Api\V1\AcademicTermController;
use App\Http\Controllers\Api\V1\AcademicYearController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceWorkflowController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurriculumRequirementController;
use App\Http\Controllers\Api\V1\FacultyController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MajorController;
use App\Http\Controllers\Api\V1\MajorSelectionController;
use App\Http\Controllers\Api\V1\MajorSelectionPeriodController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\RegistrationOptionsController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomMonitoringController;
use App\Http\Controllers\Api\V1\SchedulingCandidateController;
use App\Http\Controllers\Api\V1\SchedulingConfigurationController;
use App\Http\Controllers\Api\V1\SchedulingController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudentLifecycleController;
use App\Http\Controllers\Api\V1\StudentPlacementController;
use App\Http\Controllers\Api\V1\StudentScheduleConflictController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectOfferingController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\YearLevelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/health', HealthController::class)->name('health');

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('/login', [AuthController::class, 'login'])->name('login');
            Route::post('/register/student', [RegistrationController::class, 'registerStudent'])
                ->middleware('throttle:10,1')
                ->name('register.student');
            Route::post('/register/professor', [RegistrationController::class, 'registerProfessor'])
                ->middleware('throttle:10,1')
                ->name('register.professor');
            Route::post('/register/professor/resubmit', [RegistrationController::class, 'resubmitProfessor'])
                ->middleware('throttle:10,1')
                ->name('register.professor.resubmit');
            Route::post('/register/professor/correction', [RegistrationController::class, 'professorCorrection'])
                ->middleware('throttle:10,1')
                ->name('register.professor.correction');
            Route::get('/registration/options', RegistrationOptionsController::class)
                ->name('registration.options');

            Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
                Route::get('/me', [AuthController::class, 'me'])->name('me');
            });
            Route::post('/logout', [AuthController::class, 'logout'])
                ->middleware('auth:sanctum')
                ->name('logout');
        });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])
            ->prefix('users')
            ->name('users.')
            ->group(function (): void {
                Route::get('/options', [UserController::class, 'options'])->name('options');
                Route::get('/', [UserController::class, 'index'])->name('index');
                Route::post('/', [UserController::class, 'store'])->name('store');
                Route::get('/{user}', [UserController::class, 'show'])->name('show');
                Route::patch('/{user}', [UserController::class, 'update'])->name('update');
                Route::put('/{user}/program-access', [UserController::class, 'updateDepartmentPersonnelProgramAccess'])
                    ->name('program-access.update');
                Route::patch('/{user}/status', [UserController::class, 'updateStatus'])
                    ->name('status');
                Route::middleware('role:administrator')->group(function (): void {
                    Route::post('/departments', [UserController::class, 'storeDepartment'])
                        ->name('departments.store');
                    Route::post('/{user}/approve-professor', [UserController::class, 'approveProfessor'])
                        ->name('professor.approve');
                    Route::post('/{user}/return-professor', [UserController::class, 'returnProfessorForCorrection'])
                        ->name('professor.return');
                    Route::post('/{user}/reject-professor', [UserController::class, 'rejectProfessor'])
                        ->name('professor.reject');
                });
            });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])
            ->prefix('academic')
            ->name('academic.')
            ->group(function (): void {
                Route::get('/options', AcademicOptionsController::class)->name('options');

                Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
                Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
                Route::get('/academic-years/{academicYear}', [AcademicYearController::class, 'show'])->name('academic-years.show');
                Route::patch('/academic-years/{academicYear}', [AcademicYearController::class, 'update'])->name('academic-years.update');

                Route::get('/majors', [MajorController::class, 'index'])->name('majors.index');
                Route::post('/majors', [MajorController::class, 'store'])->name('majors.store');
                Route::get('/majors/{major}', [MajorController::class, 'show'])->name('majors.show');
                Route::patch('/majors/{major}', [MajorController::class, 'update'])->name('majors.update');

                Route::get('/curriculum/subjects', [CurriculumRequirementController::class, 'subjects'])
                    ->name('curriculum.subjects');
                Route::get('/curriculum-requirements', [CurriculumRequirementController::class, 'index'])
                    ->name('curriculum-requirements.index');
                Route::post('/curriculum-requirements', [CurriculumRequirementController::class, 'store'])
                    ->name('curriculum-requirements.store');
                Route::patch('/curriculum-requirements/{curriculumRequirement}', [CurriculumRequirementController::class, 'update'])
                    ->name('curriculum-requirements.update');
                Route::delete('/curriculum-requirements/{curriculumRequirement}', [CurriculumRequirementController::class, 'destroy'])
                    ->name('curriculum-requirements.destroy');

                Route::get('/major-selection-periods', [MajorSelectionPeriodController::class, 'index'])
                    ->name('major-selection-periods.index');
                Route::post('/major-selection-periods', [MajorSelectionPeriodController::class, 'store'])
                    ->name('major-selection-periods.store');
                Route::get('/major-selection-periods/{period}', [MajorSelectionPeriodController::class, 'show'])
                    ->name('major-selection-periods.show');
                Route::patch('/major-selection-periods/{period}', [MajorSelectionPeriodController::class, 'update'])
                    ->name('major-selection-periods.update');

                Route::get('/major-selections', [MajorSelectionController::class, 'index'])
                    ->name('major-selections.index');
                Route::patch('/major-selections/{selection}/review', [MajorSelectionController::class, 'review'])
                    ->name('major-selections.review');
                Route::patch('/major-selections/{selection}/correct', [MajorSelectionController::class, 'correct'])
                    ->name('major-selections.correct');

                Route::get('/promotions/due', [PromotionController::class, 'due'])->name('promotions.due');
                Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
                Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
                Route::get('/promotions/{promotionRun}', [PromotionController::class, 'show'])->name('promotions.show');
                Route::patch('/promotions/{promotionRun}/items/{item}', [PromotionController::class, 'adjustItem'])
                    ->name('promotions.items.update');
                Route::post('/promotions/{promotionRun}/allocate', [PromotionController::class, 'allocate'])
                    ->name('promotions.allocate');
                Route::post('/promotions/{promotionRun}/finalize', [PromotionController::class, 'finalize'])
                    ->name('promotions.finalize');

                Route::get('/programs', [AcademicProgramController::class, 'index'])->name('programs.index');
                Route::post('/programs', [AcademicProgramController::class, 'store'])->name('programs.store');
                Route::get('/programs/{program}', [AcademicProgramController::class, 'show'])->name('programs.show');
                Route::patch('/programs/{program}', [AcademicProgramController::class, 'update'])->name('programs.update');

                Route::get('/terms', [AcademicTermController::class, 'index'])->name('terms.index');
                Route::post('/terms', [AcademicTermController::class, 'store'])->name('terms.store');
                Route::get('/terms/{term}', [AcademicTermController::class, 'show'])->name('terms.show');
                Route::patch('/terms/{term}', [AcademicTermController::class, 'update'])->name('terms.update');

                Route::get('/year-levels', [YearLevelController::class, 'index'])->name('year-levels.index');
                Route::post('/year-levels', [YearLevelController::class, 'store'])->name('year-levels.store');
                Route::get('/year-levels/{year_level}', [YearLevelController::class, 'show'])->name('year-levels.show');
                Route::patch('/year-levels/{year_level}', [YearLevelController::class, 'update'])->name('year-levels.update');

                Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
                Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
                Route::get('/subjects/{subject}', [SubjectController::class, 'show'])->name('subjects.show');
                Route::patch('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');

                Route::get('/sections', [SectionController::class, 'index'])->name('sections.index');
                Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
                Route::get('/sections/{section}', [SectionController::class, 'show'])->name('sections.show');
                Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');

                Route::get('/subject-offerings/options', [SubjectOfferingController::class, 'options'])
                    ->name('subject-offerings.options');
                Route::get('/subject-offerings', [SubjectOfferingController::class, 'index'])
                    ->name('subject-offerings.index');
                Route::get('/subject-offerings/preparation-plan', [SubjectOfferingController::class, 'preparationPlan'])
                    ->name('subject-offerings.preparation-plan');
                Route::post('/subject-offerings/prepare', [SubjectOfferingController::class, 'prepare'])
                    ->name('subject-offerings.prepare');
                Route::post('/subject-offerings', [SubjectOfferingController::class, 'store'])
                    ->name('subject-offerings.store');
                Route::get('/subject-offerings/{subjectOffering}', [SubjectOfferingController::class, 'show'])
                    ->name('subject-offerings.show');
                Route::patch('/subject-offerings/{subjectOffering}', [SubjectOfferingController::class, 'update'])
                    ->name('subject-offerings.update');
                Route::patch('/subject-offerings/{subjectOffering}/status', [SubjectOfferingController::class, 'updateStatus'])
                    ->name('subject-offerings.status');
            });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])
            ->prefix('rooms')
            ->name('rooms.')
            ->group(function (): void {
                Route::get('/availability', [RoomMonitoringController::class, 'availability'])->name('availability');
                Route::get('/discrepancies', [RoomMonitoringController::class, 'discrepancies'])->name('discrepancies');
                Route::get('/', [RoomController::class, 'index'])->name('index');
                Route::post('/', [RoomController::class, 'store'])->name('store');
                Route::get('/{room}/qr', [RoomMonitoringController::class, 'qr'])->name('qr.show');
                Route::get('/{room}/qr/history', [RoomMonitoringController::class, 'qrHistory'])->name('qr.history');
                Route::post('/{room}/qr/regenerate', [RoomMonitoringController::class, 'regenerateQr'])
                    ->middleware('role:administrator')
                    ->name('qr.regenerate');
                Route::post('/{room}/qr/revoke', [RoomMonitoringController::class, 'revokeQr'])
                    ->middleware('role:administrator')
                    ->name('qr.revoke');
                Route::get('/{room}/occupancies', [RoomMonitoringController::class, 'history'])->name('occupancies');
                Route::get('/{room}', [RoomController::class, 'show'])->name('show');
                Route::patch('/{room}', [RoomController::class, 'update'])->name('update');
                Route::patch('/{room}/status', [RoomController::class, 'updateStatus'])->name('status');
                Route::post('/occupancies/{occupancy}/resolve-discrepancy', [RoomMonitoringController::class, 'resolveDiscrepancy'])
                    ->middleware('role:administrator')
                    ->name('occupancies.resolve-discrepancy');
            });

        Route::prefix('room-monitoring')
            ->name('room-monitoring.')
            ->middleware(['auth:sanctum', 'active', 'role:professor'])
            ->group(function (): void {
                Route::post('/preview', [RoomMonitoringController::class, 'preview'])
                    ->middleware('throttle:room-monitoring-scan')
                    ->name('preview');
                Route::post('/check-in', [RoomMonitoringController::class, 'checkIn'])
                    ->middleware('throttle:room-monitoring-scan')
                    ->name('check-in');
            });

        Route::prefix('students')
            ->name('students.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator,department_personnel')
                    ->get('/', [StudentController::class, 'index'])
                    ->name('index');
                Route::middleware('role:student')
                    ->get('/me', [StudentController::class, 'me'])
                    ->name('me');
                Route::middleware('role:student')
                    ->get('/me/placement', [StudentPlacementController::class, 'me'])
                    ->name('me.placement');
                Route::middleware('role:student')
                    ->post('/me/major-selection/{period}', [StudentPlacementController::class, 'selectMajor'])
                    ->name('me.major-selection');
                Route::middleware('role:administrator,department_personnel')->group(function (): void {
                    Route::get('/activity-logs', [StudentLifecycleController::class, 'activityLogs'])
                        ->name('activity-logs');
                    Route::get('/academic-history', [StudentLifecycleController::class, 'academicHistory'])
                        ->name('academic-history');
                    Route::get('/removed', [StudentLifecycleController::class, 'removed'])
                        ->name('removed');
                    Route::post('/{student}/remove', [StudentLifecycleController::class, 'remove'])
                        ->name('remove');
                    Route::post('/{student}/restore', [StudentLifecycleController::class, 'restore'])
                        ->name('restore');
                });
                Route::get('/{student}', [StudentController::class, 'show'])->name('show');
            });

        Route::prefix('faculty')
            ->name('faculty.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator,department_personnel')
                    ->get('/', [FacultyController::class, 'index'])
                    ->name('index');
                Route::middleware('role:professor')
                    ->get('/me', [FacultyController::class, 'me'])
                    ->name('me');
                Route::middleware('role:professor')
                    ->patch('/me', [FacultyController::class, 'updateMe'])
                    ->name('me.update');
                Route::middleware('role:professor')
                    ->get('/me/assignments', [FacultyController::class, 'assignmentsForCurrentUser'])
                    ->name('me.assignments');
                Route::middleware('role:professor')
                    ->get('/me/availability', [FacultyController::class, 'availabilityForCurrentUser'])
                    ->name('me.availability');
                Route::middleware('role:administrator')
                    ->patch('/{professor}/quota', [FacultyController::class, 'updateQuota'])
                    ->name('quota.update');
                Route::get('/{professor}/assignments', [FacultyController::class, 'assignments'])
                    ->name('assignments');
                Route::get('/{professor}/availability', [FacultyController::class, 'availability'])
                    ->name('availability.index');
                Route::post('/{professor}/availability', [FacultyController::class, 'storeAvailability'])
                    ->name('availability.store');
                Route::patch('/availability/{availability}', [FacultyController::class, 'updateAvailability'])
                    ->name('availability.update');
                Route::delete('/availability/{availability}', [FacultyController::class, 'destroyAvailability'])
                    ->name('availability.destroy');
                Route::get('/{professor}', [FacultyController::class, 'show'])
                    ->name('show');
            });

        Route::prefix('schedules')
            ->name('schedules.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:professor,student')
                    ->get('/me', [SchedulingController::class, 'mine'])
                    ->name('me');
                Route::get('/', [SchedulingController::class, 'index'])->name('index');
                Route::post('/', [SchedulingController::class, 'store'])->name('store');
                Route::get('/{schedule}', [SchedulingController::class, 'show'])->name('show');
                Route::patch('/{schedule}', [SchedulingController::class, 'update'])->name('update');
                Route::post('/{schedule}/validate', [SchedulingController::class, 'preview'])->name('validate');
                Route::post('/{schedule}/publish', [SchedulingController::class, 'publish'])->name('publish');
                Route::post('/{schedule}/cancel', [SchedulingController::class, 'cancel'])->name('cancel');
                Route::post('/{schedule}/assignments', [SchedulingController::class, 'storeScheduleAssignment'])
                    ->name('assignments.store');
                Route::post('/{schedule}/assignments/{assignment}/cancel', [SchedulingController::class, 'cancelScheduleAssignment'])
                    ->name('assignments.cancel');
                Route::post('/{schedule}/room-allocations', [SchedulingController::class, 'storeRoomAllocation'])
                    ->name('room-allocations.store');
                Route::post('/{schedule}/room-allocations/{allocation}/release', [SchedulingController::class, 'releaseRoomAllocation'])
                    ->name('room-allocations.release');
            });

        Route::prefix('student-schedule-conflicts')
            ->name('student-schedule-conflicts.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator,department_personnel')->group(function (): void {
                    Route::get('/options', [StudentScheduleConflictController::class, 'options'])
                        ->name('options');
                    Route::get('/', [StudentScheduleConflictController::class, 'index'])
                        ->name('index');
                });
                Route::middleware('role:student')
                    ->get('/me', [StudentScheduleConflictController::class, 'mine'])
                    ->name('me');
            });

        Route::prefix('attendance')
            ->name('attendance.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator')
                    ->get('/policies', [AttendanceWorkflowController::class, 'policies'])
                    ->name('policies.index');
                Route::middleware('role:administrator')
                    ->post('/policies', [AttendanceWorkflowController::class, 'storePolicy'])
                    ->name('policies.store');
                Route::middleware('role:administrator,department_personnel,professor,student')
                    ->get('/offerings/{offering}/ledger', [AttendanceWorkflowController::class, 'ledger'])
                    ->name('offerings.ledger');
                Route::middleware('role:professor')
                    ->get('/offerings/{offering}/ledger/export', [AttendanceWorkflowController::class, 'exportLedger'])
                    ->name('offerings.ledger.export');
                Route::middleware('role:student')
                    ->get('/excuses/me', [AttendanceWorkflowController::class, 'myExcuses'])
                    ->name('excuses.me');
                Route::middleware('role:student')
                    ->post('/sessions/{session}/excuses', [AttendanceWorkflowController::class, 'requestExcuse'])
                    ->name('sessions.excuses.store');
                Route::middleware('role:professor')
                    ->get('/offerings/{offering}/excuses', [AttendanceWorkflowController::class, 'offeringExcuses'])
                    ->name('offerings.excuses');
                Route::middleware('role:professor')
                    ->patch('/excuses/{excuse}/decision', [AttendanceWorkflowController::class, 'decideExcuse'])
                    ->name('excuses.decision');
                Route::middleware('role:administrator,department_personnel')
                    ->get('/options', [AttendanceController::class, 'options'])
                    ->name('options');
                Route::middleware('role:professor')
                    ->get('/sessions/me', [AttendanceController::class, 'sessions'])
                    ->name('sessions.me');
                Route::middleware('role:administrator,department_personnel,professor')
                    ->get('/sessions', [AttendanceController::class, 'sessions'])
                    ->name('sessions.index');
                Route::middleware('role:professor')
                    ->post('/sessions', [AttendanceController::class, 'storeSession'])
                    ->name('sessions.store');
                Route::middleware('role:administrator,department_personnel,professor')
                    ->get('/sessions/{session}', [AttendanceController::class, 'show'])
                    ->name('sessions.show');
                Route::middleware('role:administrator,professor')
                    ->patch('/sessions/{session}/status', [AttendanceController::class, 'updateStatus'])
                    ->name('sessions.status');
                Route::middleware('role:administrator,professor')
                    ->put('/sessions/{session}/records', [AttendanceController::class, 'storeRecords'])
                    ->name('sessions.records');
                Route::middleware('role:professor')
                    ->get('/sessions/{session}/qr', [AttendanceController::class, 'qrStatus'])
                    ->name('sessions.qr.show');
                Route::middleware(['role:professor', 'throttle:attendance-qr-manage'])
                    ->post('/sessions/{session}/qr', [AttendanceController::class, 'startQr'])
                    ->name('sessions.qr.start');
                Route::middleware('role:professor')
                    ->delete('/sessions/{session}/qr', [AttendanceController::class, 'stopQr'])
                    ->name('sessions.qr.stop');
                Route::middleware(['role:student', 'throttle:attendance-qr-scan'])
                    ->post('/qr/check-in', [AttendanceController::class, 'checkIn'])
                    ->name('qr.check-in');
                Route::middleware('role:student')
                    ->get('/sessions/eligible/me', [AttendanceController::class, 'eligibleSessions'])
                    ->name('sessions.eligible.me');
                Route::middleware('role:student')
                    ->get('/records/me', [AttendanceController::class, 'ownRecords'])
                    ->name('records.me');
                Route::middleware('role:student')
                    ->get('/summary/me', [AttendanceController::class, 'ownSummary'])
                    ->name('summary.me');
                Route::middleware('role:administrator,department_personnel')
                    ->get('/monitoring', [AttendanceController::class, 'monitoring'])
                    ->name('monitoring');
            });

        Route::prefix('announcements')
            ->name('announcements.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator,department_personnel')->group(function (): void {
                    Route::get('/manage', [AnnouncementController::class, 'manage'])->name('manage');
                    Route::post('/', [AnnouncementController::class, 'store'])->name('store');
                    Route::patch('/{announcement}', [AnnouncementController::class, 'update'])->name('update');
                    Route::post('/{announcement}/publish', [AnnouncementController::class, 'publish'])
                        ->name('publish');
                    Route::post('/{announcement}/archive', [AnnouncementController::class, 'archive'])
                        ->name('archive');
                    Route::get('/{announcement}/history', [AnnouncementController::class, 'history'])
                        ->name('history');
                });

                Route::get('/', [AnnouncementController::class, 'index'])->name('index');
                Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
            });

        Route::prefix('activities')
            ->name('activities.')
            ->middleware(['auth:sanctum', 'active'])
            ->group(function (): void {
                Route::middleware('role:administrator,department_personnel')->group(function (): void {
                    Route::get('/manage', [ActivityController::class, 'manage'])->name('manage');
                    Route::get('/options', [ActivityController::class, 'options'])->name('options');
                    Route::post('/', [ActivityController::class, 'store'])->name('store');
                    Route::patch('/{activity}', [ActivityController::class, 'update'])->name('update');
                    Route::post('/{activity}/publish', [ActivityController::class, 'publish'])
                        ->name('publish');
                    Route::post('/{activity}/cancel', [ActivityController::class, 'cancel'])
                        ->name('cancel');
                    Route::post('/{activity}/complete', [ActivityController::class, 'complete'])
                        ->name('complete');
                    Route::get('/{activity}/history', [ActivityController::class, 'history'])
                        ->name('history');
                });

                Route::get('/', [ActivityController::class, 'index'])->name('index');
                Route::get('/{activity}', [ActivityController::class, 'show'])->name('show');
            });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])->group(function (): void {
            Route::get('/scheduling/options', [SchedulingController::class, 'options'])
                ->name('scheduling.options');
            Route::get('/scheduling/readiness', [SchedulingController::class, 'readiness'])
                ->name('scheduling.readiness');
            Route::get('/scheduling/generations', [SchedulingController::class, 'history'])
                ->name('scheduling.generations.history');
            Route::post('/scheduling/solve', [SchedulingController::class, 'solve'])
                ->name('scheduling.solve');

            Route::get('/scheduling/candidates/{candidate}', [SchedulingCandidateController::class, 'show'])
                ->name('scheduling.candidates.show');
            Route::post('/scheduling/candidates/{candidate}/revisions', [SchedulingCandidateController::class, 'storeRevision'])
                ->name('scheduling.candidates.revisions.store');
            Route::get('/scheduling/candidate-revisions/{revision}', [SchedulingCandidateController::class, 'showRevision'])
                ->name('scheduling.candidate-revisions.show');
            Route::patch('/scheduling/candidate-revisions/{revision}', [SchedulingCandidateController::class, 'updateRevision'])
                ->name('scheduling.candidate-revisions.update');
            Route::post('/scheduling/candidate-revisions/{revision}/validate', [SchedulingCandidateController::class, 'validateRevision'])
                ->name('scheduling.candidate-revisions.validate');
            Route::post('/scheduling/candidate-revisions/{revision}/transition', [SchedulingCandidateController::class, 'transition'])
                ->name('scheduling.candidate-revisions.transition');
            Route::get('/scheduling/candidate-revisions/{revision}/status-events', [SchedulingCandidateController::class, 'statusEvents'])
                ->name('scheduling.candidate-revisions.status-events');

            Route::prefix('scheduling/configuration')
                ->name('scheduling.configuration.')
                ->group(function (): void {
                    Route::get('/time-grid', [SchedulingConfigurationController::class, 'timeGrid'])
                        ->name('time-grid.show');
                    Route::post('/time-grids', [SchedulingConfigurationController::class, 'storeTimeGrid'])
                        ->name('time-grids.store');
                    Route::patch('/time-grids/{timeGrid}', [SchedulingConfigurationController::class, 'updateTimeGrid'])
                        ->name('time-grids.update');
                    Route::delete('/time-grids/{timeGrid}', [SchedulingConfigurationController::class, 'destroyTimeGrid'])
                        ->name('time-grids.destroy');
                    Route::post('/time-grids/{timeGrid}/approve', [SchedulingConfigurationController::class, 'approveTimeGrid'])
                        ->name('time-grids.approve');
                    Route::post('/time-grids/{timeGrid}/revise', [SchedulingConfigurationController::class, 'reviseTimeGrid'])
                        ->name('time-grids.revise');

                    Route::get('/full-time-policies', [SchedulingConfigurationController::class, 'fullTimePolicies'])
                        ->name('full-time-policies.index');
                    Route::post('/full-time-policies', [SchedulingConfigurationController::class, 'storeFullTimePolicy'])
                        ->name('full-time-policies.store');
                    Route::patch('/full-time-policies/{fullTimePolicy}', [SchedulingConfigurationController::class, 'updateFullTimePolicy'])
                        ->name('full-time-policies.update');
                    Route::delete('/full-time-policies/{fullTimePolicy}', [SchedulingConfigurationController::class, 'destroyFullTimePolicy'])
                        ->name('full-time-policies.destroy');
                    Route::post('/full-time-policies/{fullTimePolicy}/approve', [SchedulingConfigurationController::class, 'approveFullTimePolicy'])
                        ->name('full-time-policies.approve');
                    Route::post('/full-time-policies/{fullTimePolicy}/revise', [SchedulingConfigurationController::class, 'reviseFullTimePolicy'])
                        ->name('full-time-policies.revise');

                    Route::get('/meeting-plans', [SchedulingConfigurationController::class, 'meetingPlans'])
                        ->name('meeting-plans.index');
                    Route::post('/meeting-plans', [SchedulingConfigurationController::class, 'storeMeetingPlan'])
                        ->name('meeting-plans.store');
                    Route::patch('/meeting-plans/{meetingPlan}', [SchedulingConfigurationController::class, 'updateMeetingPlan'])
                        ->name('meeting-plans.update');
                    Route::delete('/meeting-plans/{meetingPlan}', [SchedulingConfigurationController::class, 'destroyMeetingPlan'])
                        ->name('meeting-plans.destroy');
                    Route::post('/meeting-plans/{meetingPlan}/approve', [SchedulingConfigurationController::class, 'approveMeetingPlan'])
                        ->name('meeting-plans.approve');
                    Route::post('/meeting-plans/{meetingPlan}/revise', [SchedulingConfigurationController::class, 'reviseMeetingPlan'])
                        ->name('meeting-plans.revise');
                });
            Route::get('/faculty-assignments', [SchedulingController::class, 'facultyAssignments'])
                ->name('faculty-assignments.index');
            Route::post('/faculty-assignments', [SchedulingController::class, 'storeFacultyAssignment'])
                ->name('faculty-assignments.store');
            Route::patch('/faculty-assignments/{assignment}/status', [SchedulingController::class, 'updateFacultyAssignmentStatus'])
                ->name('faculty-assignments.status');
            Route::get('/schedule-conflicts', [SchedulingController::class, 'conflicts'])
                ->name('schedule-conflicts.index');
            Route::post('/schedule-conflicts/{conflict}/resolve', [SchedulingController::class, 'resolveConflict'])
                ->name('schedule-conflicts.resolve');
            Route::post('/schedule-conflicts/{conflict}/waive', [SchedulingController::class, 'waiveConflict'])
                ->name('schedule-conflicts.waive');
        });
    });
