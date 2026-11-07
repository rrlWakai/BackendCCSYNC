<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentSubjectEnrollment;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use Database\Seeders\Concerns\RefuseProtectedDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentAccountSeeder extends Seeder
{
    use RefuseProtectedDatabase;

    public function run(): void
    {
        $this->refuseProtectedDatabase();

        if (app()->environment('production')
            && ! filter_var(env('CCSYNC_ALLOW_PRODUCTION_SEED', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException(
                'Refusing to create development accounts in production. '
                .'Set CCSYNC_ALLOW_PRODUCTION_SEED=true only through a controlled operator process.',
            );
        }

        $password = env('CCSYNC_SEED_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'CCSYNC_SEED_PASSWORD must be set before running the development account seeder.',
            );
        }

        $roles = Role::whereIn('code', [
            Role::ADMINISTRATOR,
            Role::DEPARTMENT_PERSONNEL,
            Role::PROFESSOR,
            Role::STUDENT,
        ])->pluck('id', 'code');

        if ($roles->count() !== 4) {
            throw new RuntimeException('Run RoleSeeder before DevelopmentAccountSeeder.');
        }

        $bsit = Program::where('code', 'BSIT')->firstOrFail();
        $currentTerm = AcademicTerm::current()->firstOrFail();
        $section = Section::where('academic_term_id', $currentTerm->id)
            ->where('code', 'BSIT-1A')
            ->firstOrFail();
        $it101 = Subject::where('code', 'IT101')->firstOrFail();

        $admin = User::updateOrCreate(
            ['email' => 'admin@ccsync.test'],
            [
                'role_id' => $roles[Role::ADMINISTRATOR],
                'name' => 'CCSync Administrator',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'status' => 'active',
            ],
        );

        User::updateOrCreate(
            ['email' => 'personnel@ccsync.test'],
            [
                'role_id' => $roles[Role::DEPARTMENT_PERSONNEL],
                'name' => 'CCSync Department Personnel',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'status' => 'active',
            ],
        );

        $professorUser = User::updateOrCreate(
            ['email' => 'professor@ccsync.test'],
            [
                'role_id' => $roles[Role::PROFESSOR],
                'name' => 'Professor Account',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'status' => 'active',
            ],
        );

        $professor = Professor::updateOrCreate(
            ['user_id' => $professorUser->id],
            [
                'employee_number' => 'EMP-0001',
                'program_id' => $bsit->id,
                'employment_type' => 'full_time',
                'specialization' => 'Information Technology',
                'status' => 'active',
            ],
        );

        $studentUser = User::updateOrCreate(
            ['email' => 'student@ccsync.test'],
            [
                'role_id' => $roles[Role::STUDENT],
                'name' => 'Student Account',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'status' => 'active',
            ],
        );

        $student = Student::updateOrCreate(
            ['user_id' => $studentUser->id],
            [
                'student_number' => '2026-0001',
                'program_id' => $bsit->id,
                'admitted_on' => '2026-08-03',
                'status' => 'active',
            ],
        );

        $offering = SubjectOffering::updateOrCreate(
            [
                'academic_term_id' => $currentTerm->id,
                'section_id' => $section->id,
                'subject_id' => $it101->id,
            ],
            ['expected_students' => 45, 'status' => 'open'],
        );

        StudentSubjectEnrollment::updateOrCreate(
            [
                'student_id' => $student->id,
                'subject_offering_id' => $offering->id,
            ],
            ['status' => 'enrolled', 'enrolled_at' => now()],
        );

        FacultyAssignment::updateOrCreate(
            [
                'professor_id' => $professor->id,
                'subject_offering_id' => $offering->id,
                'assignment_role' => 'lead',
            ],
            [
                'assigned_units' => $it101->units,
                'assigned_by_user_id' => $admin->id,
                'status' => 'active',
            ],
        );

        $this->command?->info('Development accounts seeded without displaying passwords.');
    }
}
