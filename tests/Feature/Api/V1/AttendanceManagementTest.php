<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\AttendanceSession;
use App\Models\Role;
use App\Models\ScheduleAssignment;
use App\Models\StudentAttendance;
use App\Models\StudentSubjectEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AttendanceManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    private ?AcademicTerm $attendanceTerm = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_professor_can_create_one_session_for_an_assigned_published_class_date(): void
    {
        $fixture = $this->makeAttendanceFixture();

        $response = $this->actingAs($fixture['professor_user'])->postJson('/api/v1/attendance/sessions', [
            'class_schedule_id' => $fixture['schedule']->id,
            'class_date' => '2026-11-02',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.session.class_date', '2026-11-02')
            ->assertJsonPath('data.session.status', 'open')
            ->assertJsonPath('data.session.offering.subject.code', $fixture['subject_code']);

        $sessionId = $response->json('data.session.id');
        $this->postJson('/api/v1/attendance/sessions', [
            'class_schedule_id' => $fixture['schedule']->id,
            'class_date' => '2026-11-02',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['class_date']]);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $sessionId,
            'created_by_user_id' => $fixture['professor_user']->id,
        ]);
    }

    public function test_attendance_session_rejects_unassigned_cancelled_future_and_wrong_weekday_schedules(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $otherProfessor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $fixture['program']);
        $otherUser = $otherProfessor->user;
        $this->actingAs($otherUser)
            ->postJson('/api/v1/attendance/sessions', [
                'class_schedule_id' => $fixture['schedule']->id,
                'class_date' => '2026-11-02',
            ])
            ->assertForbidden();

        $this->actingAs($fixture['professor_user'])
            ->postJson('/api/v1/attendance/sessions', [
                'class_schedule_id' => $fixture['schedule']->id,
                'class_date' => '2026-11-03',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['class_date']]);

        $this->postJson('/api/v1/attendance/sessions', [
            'class_schedule_id' => $fixture['schedule']->id,
            'class_date' => '2026-11-09',
        ])->assertUnprocessable();

        $fixture['schedule']->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/attendance/sessions', [
            'class_schedule_id' => $fixture['schedule']->id,
            'class_date' => '2026-11-02',
        ])->assertForbidden();
    }

    public function test_professor_can_record_and_submit_the_complete_enrolled_roster(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $secondStudent = $this->makeStudent($this->makeUser(Role::STUDENT), $fixture['program']);
        $secondEnrollment = StudentSubjectEnrollment::create([
            'student_id' => $secondStudent->id,
            'subject_offering_id' => $fixture['offering']->id,
            'status' => 'enrolled',
            'enrolled_at' => '2026-08-01 08:00:00',
        ]);
        $session = $this->createSession($fixture);

        $this->actingAs($fixture['professor_user'])
            ->getJson('/api/v1/attendance/sessions/'.$session->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.roster')
            ->assertJsonMissingPath('data.roster.0.student.email');

        $this->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
            'records' => [
                [
                    'student_subject_enrollment_id' => $fixture['enrollment']->id,
                    'status' => 'present',
                    'check_in_at' => '08:05',
                ],
                [
                    'student_subject_enrollment_id' => $secondEnrollment->id,
                    'status' => 'late',
                    'check_in_at' => '08:20',
                ],
            ],
        ])->assertOk();

        $this->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
            'status' => 'submitted',
        ])->assertOk()->assertJsonPath('data.session.status', 'submitted');

        $this->assertSame(2, StudentAttendance::query()
            ->where('attendance_session_id', $session->id)
            ->count());
    }

    public function test_excused_attendance_requires_remarks_and_bulk_validation_rolls_back(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);

        $this->actingAs($fixture['professor_user'])
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
                'records' => [[
                    'student_subject_enrollment_id' => $fixture['enrollment']->id,
                    'status' => 'excused',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['records.0.remarks']]);

        $otherFixture = $this->makeAttendanceFixture();
        $this->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
            'records' => [
                [
                    'student_subject_enrollment_id' => $fixture['enrollment']->id,
                    'status' => 'present',
                ],
                [
                    'student_subject_enrollment_id' => $otherFixture['enrollment']->id,
                    'status' => 'absent',
                ],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('student_attendance', [
            'attendance_session_id' => $session->id,
        ]);
    }

    public function test_submitted_records_require_reason_and_append_correction_history(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $this->actingAs($fixture['professor_user'])
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
                'records' => [[
                    'student_subject_enrollment_id' => $fixture['enrollment']->id,
                    'status' => 'present',
                    'remarks' => 'On time',
                ]],
            ])->assertOk();
        $this->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
            'status' => 'submitted',
        ])->assertOk();

        $this->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
            'records' => [[
                'student_subject_enrollment_id' => $fixture['enrollment']->id,
                'status' => 'late',
                'remarks' => 'Arrived after the opening check',
            ]],
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['correction_reason']]);

        $this->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
            'records' => [[
                'student_subject_enrollment_id' => $fixture['enrollment']->id,
                'status' => 'late',
                'remarks' => 'Arrived after the opening check',
            ]],
            'correction_reason' => 'Professor confirmed the corrected arrival time.',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_record_changes', [
            'student_attendance_id' => StudentAttendance::query()
                ->where('attendance_session_id', $session->id)
                ->value('id'),
            'previous_status' => 'present',
            'new_status' => 'late',
            'changed_by_user_id' => $fixture['professor_user']->id,
            'correction_reason' => 'Professor confirmed the corrected arrival time.',
        ]);
    }

    public function test_department_personnel_access_is_program_scoped_and_unassigned_users_are_denied(): void
    {
        $first = $this->makeAttendanceFixture();
        $second = $this->makeAttendanceFixture();
        $firstSession = $this->createSession($first);
        $secondSession = $this->createSession($second);
        StudentAttendance::create([
            'attendance_session_id' => $firstSession->id,
            'student_subject_enrollment_id' => $first['enrollment']->id,
            'status' => 'present',
        ]);
        StudentAttendance::create([
            'attendance_session_id' => $secondSession->id,
            'student_subject_enrollment_id' => $second['enrollment']->id,
            'status' => 'present',
        ]);
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);

        $this->actingAs($departmentPersonnel)
            ->getJson('/api/v1/attendance/sessions')
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/v1/users/'.$departmentPersonnel->id.'/program-access', [
                'program_ids' => [$first['program']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.user.program_access.0.id', $first['program']->id);

        $this->actingAs($departmentPersonnel)
            ->getJson('/api/v1/attendance/options')
            ->assertOk()
            ->assertJsonPath('data.programs.0.id', $first['program']->id)
            ->assertJsonCount(1, 'data.programs');

        $this->actingAs($departmentPersonnel)
            ->getJson('/api/v1/attendance/sessions')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.sessions.0.id', $firstSession->id);

        $this->getJson('/api/v1/attendance/sessions/'.$secondSession->id)->assertForbidden();
        $this->getJson('/api/v1/attendance/monitoring')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.records.0.session.id', $firstSession->id);

        $this->actingAs($departmentPersonnel)
            ->putJson('/api/v1/users/'.$departmentPersonnel->id.'/program-access', [
                'program_ids' => [$second['program']->id],
            ])
            ->assertForbidden();
    }

    public function test_administrator_can_remove_department_personnel_program_access(): void
    {
        $program = $this->makeProgram();
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $departmentPersonnel->departmentPersonnelPrograms()->attach($program);

        $this->actingAs($admin)
            ->putJson('/api/v1/users/'.$departmentPersonnel->id.'/program-access', [
                'program_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.user.program_access', []);

        $this->assertDatabaseMissing('department_personnel_programs', [
            'user_id' => $departmentPersonnel->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_program_access_rejects_duplicate_and_inactive_programs(): void
    {
        $activeProgram = $this->makeProgram();
        $inactiveProgram = $this->makeProgram();
        $inactiveProgram->update(['status' => 'inactive']);
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $url = '/api/v1/users/'.$departmentPersonnel->id.'/program-access';

        $this->actingAs($admin)
            ->putJson($url, ['program_ids' => [$activeProgram->id, $activeProgram->id]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['program_ids.1']]);

        $this->putJson($url, ['program_ids' => [$inactiveProgram->id]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['program_ids.0']]);

        $this->assertDatabaseMissing('department_personnel_programs', [
            'user_id' => $departmentPersonnel->id,
            'program_id' => $activeProgram->id,
        ]);
        $this->assertDatabaseMissing('department_personnel_programs', [
            'user_id' => $departmentPersonnel->id,
            'program_id' => $inactiveProgram->id,
        ]);
    }

    public function test_only_administrators_can_manage_department_personnel_program_access(): void
    {
        $program = $this->makeProgram();
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $url = '/api/v1/users/'.$departmentPersonnel->id.'/program-access';

        foreach ([Role::DEPARTMENT_PERSONNEL, Role::PROFESSOR, Role::STUDENT] as $role) {
            $this->actingAs($this->makeUser($role))
                ->putJson($url, ['program_ids' => [$program->id]])
                ->assertForbidden();
        }

        $student = $this->makeUser(Role::STUDENT);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->putJson('/api/v1/users/'.$student->id.'/program-access', [
                'program_ids' => [$program->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('department_personnel_programs', [
            'user_id' => $departmentPersonnel->id,
            'program_id' => $program->id,
        ]);
        $this->assertDatabaseMissing('department_personnel_programs', [
            'user_id' => $student->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_administrator_can_correct_but_department_personnel_cannot_change_attendance(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $this->actingAs($fixture['professor_user'])
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
                'records' => [[
                    'student_subject_enrollment_id' => $fixture['enrollment']->id,
                    'status' => 'present',
                ]],
            ])->assertOk();
        $this->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
            'status' => 'submitted',
        ])->assertOk();
        $record = StudentAttendance::query()->where('attendance_session_id', $session->id)->firstOrFail();
        $payload = [
            'records' => [[
                'student_subject_enrollment_id' => $fixture['enrollment']->id,
                'status' => 'absent',
                'remarks' => 'Administrative correction',
            ]],
            'correction_reason' => 'Verified against the signed class register.',
        ];

        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $departmentPersonnel->departmentPersonnelPrograms()->attach($fixture['program']->id);
        $this->actingAs($departmentPersonnel)
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', $payload)
            ->assertForbidden();

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', $payload)
            ->assertOk();

        $this->assertDatabaseHas('student_attendance', ['id' => $record->id, 'status' => 'absent']);
    }

    public function test_students_only_see_their_own_records_and_cancelled_sessions_remain_historical(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $otherStudent = $this->makeStudent($this->makeUser(Role::STUDENT), $fixture['program']);
        $otherEnrollment = StudentSubjectEnrollment::create([
            'student_id' => $otherStudent->id,
            'subject_offering_id' => $fixture['offering']->id,
            'status' => 'enrolled',
            'enrolled_at' => '2026-08-01 08:00:00',
        ]);
        $session = $this->createSession($fixture);
        $this->actingAs($fixture['professor_user'])
            ->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
                'records' => [
                    ['student_subject_enrollment_id' => $fixture['enrollment']->id, 'status' => 'present'],
                    ['student_subject_enrollment_id' => $otherEnrollment->id, 'status' => 'absent'],
                ],
            ])->assertOk();
        $this->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
            'status' => 'submitted',
        ])->assertOk();

        $this->actingAs($fixture['student_user'])
            ->getJson('/api/v1/attendance/records/me')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.records.0.attendance.student.id', $fixture['student']->id)
            ->assertJsonMissing(['email' => $otherStudent->user->email]);
        $this->getJson('/api/v1/attendance/sessions/'.$session->id)->assertForbidden();

        $this->actingAs($fixture['professor_user'])
            ->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
                'status' => 'cancelled',
                'cancellation_reason' => 'The class meeting was later declared cancelled.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('student_attendance', [
            'attendance_session_id' => $session->id,
            'student_subject_enrollment_id' => $fixture['enrollment']->id,
            'status' => 'present',
        ]);
        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_other_professor_cannot_view_or_change_an_attendance_session(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $otherProfessor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $fixture['program']);

        $this->actingAs($otherProfessor->user)
            ->getJson('/api/v1/attendance/sessions/'.$session->id)
            ->assertForbidden();

        $this->putJson('/api/v1/attendance/sessions/'.$session->id.'/records', [
            'records' => [[
                'student_subject_enrollment_id' => $fixture['enrollment']->id,
                'status' => 'absent',
            ]],
        ])->assertForbidden();
    }

    private function createSession(array $fixture): AttendanceSession
    {
        $response = $this->actingAs($fixture['professor_user'])->postJson('/api/v1/attendance/sessions', [
            'class_schedule_id' => $fixture['schedule']->id,
            'class_date' => '2026-11-02',
        ]);
        $response->assertCreated();

        return AttendanceSession::query()->findOrFail($response->json('data.session.id'));
    }

    private function makeAttendanceFixture(): array
    {
        $program = $this->makeProgram();
        $term = $this->attendanceTerm ??= $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering);
        ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $studentUser = $this->makeUser(Role::STUDENT);
        $student = $this->makeStudent($studentUser, $program);
        $enrollment = StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => '2026-08-01 08:00:00',
        ]);

        return [
            'program' => $program,
            'offering' => $offering,
            'schedule' => $schedule,
            'professor_user' => $professor->user,
            'student_user' => $studentUser,
            'student' => $student,
            'enrollment' => $enrollment,
            'subject_code' => $subject->code,
        ];
    }
}
