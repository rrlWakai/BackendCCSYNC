<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\AttendanceQrToken;
use App\Models\AttendanceSession;
use App\Models\Role;
use App\Models\ScheduleAssignment;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class QrAttendanceTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    private ?AcademicTerm $attendanceTerm = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:05:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_professor_can_start_qr_attendance_for_an_authorized_session(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);

        $response = $this->actingAs($fixture['professor_user'])
            ->postJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.qr.active', true)
            ->assertJsonPath('data.qr.monitoring.total_enrolled', 1)
            ->assertJsonPath('data.qr.monitoring.checked_in', 0);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(20, strlen($token));

        $qrToken = AttendanceQrToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $qrToken->token_hash);
        $this->assertNotSame($token, $qrToken->token_hash);
        $this->assertNull($qrToken->revoked_at);
        $this->assertNotNull($session->fresh()->qr_opened_at);
    }

    public function test_professor_cannot_start_qr_attendance_for_an_unauthorized_session(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $otherProfessor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $fixture['program']);

        $this->actingAs($otherProfessor->user)
            ->postJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertForbidden();

        $this->actingAs($otherProfessor->user)
            ->getJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_qr_tokens', 0);
    }

    public function test_non_professor_roles_cannot_manage_or_generate_qr_codes(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $departmentPersonnel->departmentPersonnelPrograms()->attach($fixture['program']->id);

        foreach ([
            $this->makeUser(Role::ADMINISTRATOR),
            $departmentPersonnel,
            $fixture['student_user'],
        ] as $user) {
            $this->actingAs($user)
                ->postJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
                ->assertForbidden();
        }

        $this->assertDatabaseCount('attendance_qr_tokens', 0);
    }

    public function test_student_can_check_in_with_a_valid_token_and_monitoring_updates(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'present')
            ->assertJsonPath('data.attendance.submission_source', 'qr')
            ->assertJsonPath('data.session.id', $session->id);

        $this->assertDatabaseHas('student_attendance', [
            'attendance_session_id' => $session->id,
            'student_subject_enrollment_id' => $fixture['enrollment']->id,
            'status' => 'present',
            'submission_source' => 'qr',
            'recorded_by_user_id' => $fixture['student_user']->id,
        ]);

        $this->actingAs($fixture['professor_user'])
            ->getJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.qr.monitoring.checked_in', 1)
            ->assertJsonPath('data.qr.monitoring.not_checked_in', 0)
            ->assertJsonPath('data.qr.monitoring.percentage', 100)
            ->assertJsonPath('data.qr.monitoring.checked_in_students.0.student.id', $fixture['student']->id);
    }

    public function test_early_check_in_uses_campus_local_time(): void
    {
        $fixture = $this->makeAttendanceFixture();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 07:05:00', 'Asia/Manila'));
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'present')
            ->assertJsonPath('data.attendance.check_in_at', '07:05');

        $this->assertDatabaseHas('student_attendance', [
            'attendance_session_id' => $session->id,
            'status' => 'present',
            'check_in_at' => '07:05:00',
        ]);
    }

    public function test_check_in_after_the_late_threshold_is_recorded_as_late(): void
    {
        $fixture = $this->makeAttendanceFixture();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:20:00', 'Asia/Manila'));
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'late')
            ->assertJsonPath('data.attendance.check_in_at', '08:20');

        $this->assertDatabaseHas('student_attendance', [
            'attendance_session_id' => $session->id,
            'status' => 'late',
            'check_in_at' => '08:20:00',
            'submission_source' => 'qr',
        ]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:05:31', 'Asia/Manila'));

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertStatus(410)
            ->assertJsonPath('code', 'token_expired');

        $this->assertDatabaseCount('student_attendance', 0);
    }

    public function test_rotating_a_token_invalidates_the_previous_token(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $firstToken = $this->startQr($session, $fixture['professor_user'])['token'];
        $secondToken = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->assertNotSame($firstToken, $secondToken);

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $firstToken])
            ->assertStatus(422)
            ->assertJsonPath('code', 'token_invalid');

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $secondToken])
            ->assertOk();

        $this->assertDatabaseCount('student_attendance', 1);
    }

    public function test_stopping_qr_attendance_invalidates_the_token(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['professor_user'])
            ->deleteJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.qr.active', false);

        $this->assertNotNull($session->fresh()->qr_closed_at);

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'session_closed');

        $this->assertDatabaseCount('student_attendance', 0);
    }

    public function test_submitted_session_rejects_qr_check_in_even_with_an_existing_token(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

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

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'session_closed');
    }

    public function test_cancelled_session_rejects_qr_check_in(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['professor_user'])
            ->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
                'status' => 'cancelled',
                'cancellation_reason' => 'The class meeting was cancelled.',
            ])->assertOk();

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'session_cancelled');

        $this->assertDatabaseCount('student_attendance', 0);
    }

    public function test_student_without_an_eligible_enrollment_is_rejected(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];
        $outsider = $this->makeStudent($this->makeUser(Role::STUDENT), $fixture['program']);

        $this->actingAs($outsider->user)
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_enrolled');

        $this->assertDatabaseCount('student_attendance', 0);
    }

    public function test_duplicate_qr_check_in_is_prevented(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertOk();

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_recorded');

        $this->assertDatabaseCount('student_attendance', 1);
    }

    public function test_student_cannot_submit_attendance_for_another_student(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $otherUser = $this->makeUser(Role::STUDENT);
        $otherStudent = $this->makeStudent($otherUser, $fixture['program']);
        $otherEnrollment = StudentSubjectEnrollment::create([
            'student_id' => $otherStudent->id,
            'subject_offering_id' => $fixture['offering']->id,
            'status' => 'enrolled',
            'enrolled_at' => '2026-08-01 08:00:00',
        ]);

        $this->actingAs($otherUser)
            ->postJson('/api/v1/attendance/qr/check-in', [
                'token' => $token,
                'student_id' => $fixture['student']->id,
                'student_subject_enrollment_id' => $fixture['enrollment']->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.attendance.student.id', $otherStudent->id);

        $this->assertDatabaseHas('student_attendance', [
            'attendance_session_id' => $session->id,
            'student_subject_enrollment_id' => $otherEnrollment->id,
        ]);
        $this->assertDatabaseMissing('student_attendance', [
            'attendance_session_id' => $session->id,
            'student_subject_enrollment_id' => $fixture['enrollment']->id,
        ]);
    }

    public function test_inactive_student_account_cannot_check_in(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $token = $this->startQr($session, $fixture['professor_user'])['token'];

        $fixture['student_user']->update(['status' => 'inactive']);

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', ['token' => $token])
            ->assertForbidden();

        $this->assertDatabaseCount('student_attendance', 0);
    }

    public function test_professor_cannot_start_qr_for_a_cancelled_session(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);

        $this->actingAs($fixture['professor_user'])
            ->patchJson('/api/v1/attendance/sessions/'.$session->id.'/status', [
                'status' => 'cancelled',
                'cancellation_reason' => 'The class meeting was cancelled.',
            ])->assertOk();

        $this->actingAs($fixture['professor_user'])
            ->postJson('/api/v1/attendance/sessions/'.$session->id.'/qr')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['session']]);

        $this->assertDatabaseCount('attendance_qr_tokens', 0);
    }

    public function test_invalid_and_unknown_tokens_are_rejected(): void
    {
        $fixture = $this->makeAttendanceFixture();
        $session = $this->createSession($fixture);
        $this->startQr($session, $fixture['professor_user']);

        $this->actingAs($fixture['student_user'])
            ->postJson('/api/v1/attendance/qr/check-in', [
                'token' => str_repeat('a', 43),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'token_invalid');

        $this->assertDatabaseCount('student_attendance', 0);
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

    private function startQr(AttendanceSession $session, User $professor): array
    {
        $response = $this->actingAs($professor)
            ->postJson('/api/v1/attendance/sessions/'.$session->id.'/qr');
        $response->assertOk();

        return $response->json('data');
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
