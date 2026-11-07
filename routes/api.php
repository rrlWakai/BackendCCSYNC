<?php

use App\Http\Controllers\Api\V1\AcademicOptionsController;
use App\Http\Controllers\Api\V1\AcademicProgramController;
use App\Http\Controllers\Api\V1\AcademicTermController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FacultyController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\SchedulingController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudentScheduleConflictController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\YearLevelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/health', HealthController::class)->name('health');

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('/login', [AuthController::class, 'login'])->name('login');

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
            });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])
            ->prefix('academic')
            ->name('academic.')
            ->group(function (): void {
                Route::get('/options', AcademicOptionsController::class)->name('options');

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
            });

        Route::middleware(['auth:sanctum', 'active', 'role:administrator,department_personnel'])
            ->prefix('rooms')
            ->name('rooms.')
            ->group(function (): void {
                Route::get('/', [RoomController::class, 'index'])->name('index');
                Route::post('/', [RoomController::class, 'store'])->name('store');
                Route::get('/{room}', [RoomController::class, 'show'])->name('show');
                Route::patch('/{room}', [RoomController::class, 'update'])->name('update');
                Route::patch('/{room}/status', [RoomController::class, 'updateStatus'])->name('status');
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
