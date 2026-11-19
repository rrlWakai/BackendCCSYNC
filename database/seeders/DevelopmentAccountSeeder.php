<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\CurriculumRequirement;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\Major;
use App\Models\MajorSelectionPeriod;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Role;
use App\Models\Room;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentMajorSelection;
use App\Models\StudentSubjectEnrollment;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Models\YearLevel;
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
        $academicYear = AcademicYear::where('label', '2026-2027')->firstOrFail();
        $department = Department::where('code', 'CCS')->firstOrFail();
        $currentTerm = AcademicTerm::current()->firstOrFail();
        $section = Section::where('academic_term_id', $currentTerm->id)
            ->where('code', 'BSIT-1A')
            ->firstOrFail();
        $it101 = Subject::where('code', 'IT101')->firstOrFail();
        $thirdYear = YearLevel::where('level', 3)->firstOrFail();
        $wmad = Major::where('program_id', $bsit->id)->where('code', 'WMAD')->firstOrFail();

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
                'department_id' => $department->id,
                'employment_type' => 'full_time',
                'weekly_teaching_hours_quota' => 12.0,
                'specialization' => 'Information Technology',
                'status' => 'active',
            ],
        );

        foreach ([
            ['professor2@ccsync.test', '[DEMO] Professor Two', 'DEMO-EMP-0002', 'Database Systems'],
            ['professor3@ccsync.test', '[DEMO] Professor Three', 'DEMO-EMP-0003', 'Web Development'],
        ] as [$email, $name, $employeeNumber, $specialization]) {
            $demoUser = User::firstOrCreate(
                ['email' => $email],
                [
                    'role_id' => $roles[Role::PROFESSOR],
                    'name' => $name,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                    'status' => 'active',
                ],
            );

            Professor::firstOrCreate(
                ['employee_number' => $employeeNumber],
                [
                    'user_id' => $demoUser->id,
                    'program_id' => $bsit->id,
                    'department_id' => $department->id,
                    'employment_type' => 'full_time',
                    'weekly_teaching_hours_quota' => 12.0,
                    'specialization' => $specialization,
                    'status' => 'active',
                ],
            );
        }

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

        StudentSubjectEnrollment::firstOrCreate(
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

        Room::firstOrCreate(
            ['code' => 'DEMO-LAB-401'],
            [
                'name' => '[DEMO] Synthetic Laboratory 401',
                'building' => 'Demo',
                'floor' => '4',
                'capacity' => 25,
                'room_type' => 'laboratory',
                'status' => 'available',
            ],
        );

        $demoSubjects = [
            [
                'code' => 'DEMO-GEN-101',
                'title' => '[DEMO] Synthetic Academic Literacy',
                'description' => 'Synthetic development-only subject. This is not official LSPU curriculum.',
                'units' => 3.0,
                'lecture_hours' => 3.0,
                'lab_hours' => 0.0,
            ],
            [
                'code' => 'DEMO-WMAD-301',
                'title' => '[DEMO] Synthetic Web Practicum',
                'description' => 'Synthetic development-only subject. This is not official LSPU curriculum.',
                'units' => 3.0,
                'lecture_hours' => 2.0,
                'lab_hours' => 3.0,
            ],
            [
                'code' => 'DEMO-WMAD-302',
                'title' => '[DEMO] Synthetic Mobile Practicum',
                'description' => 'Synthetic development-only subject. This is not official LSPU curriculum.',
                'units' => 3.0,
                'lecture_hours' => 2.0,
                'lab_hours' => 3.0,
            ],
        ];

        foreach ($demoSubjects as $subjectAttributes) {
            Subject::firstOrCreate(
                ['code' => $subjectAttributes['code']],
                $subjectAttributes + ['status' => 'active'],
            );
        }

        $firstYear = YearLevel::where('level', 1)->firstOrFail();
        $demoSyntheticSubjects = [
            [
                'program_id' => $bsit->id,
                'year_level_id' => $firstYear->id,
                'semester' => 'first',
                'major_id' => null,
                'classification' => 'common',
                'subject_id' => Subject::where('code', 'DEMO-GEN-101')->firstOrFail()->id,
            ],
            [
                'program_id' => $bsit->id,
                'year_level_id' => $thirdYear->id,
                'semester' => 'first',
                'major_id' => $wmad->id,
                'classification' => 'major',
                'subject_id' => Subject::where('code', 'DEMO-WMAD-301')->firstOrFail()->id,
            ],
            [
                'program_id' => $bsit->id,
                'year_level_id' => $thirdYear->id,
                'semester' => 'second',
                'major_id' => $wmad->id,
                'classification' => 'major',
                'subject_id' => Subject::where('code', 'DEMO-WMAD-302')->firstOrFail()->id,
            ],
        ];

        foreach ($demoSyntheticSubjects as $mapping) {
            CurriculumRequirement::firstOrCreate(
                [
                    'program_id' => $mapping['program_id'],
                    'year_level_id' => $mapping['year_level_id'],
                    'semester' => $mapping['semester'],
                    'major_id' => $mapping['major_id'],
                    'subject_id' => $mapping['subject_id'],
                ],
                $mapping + ['requirement_type' => 'core', 'status' => 'active'],
            );
        }

        $selectionPeriod = MajorSelectionPeriod::firstOrCreate(
            [
                'academic_year_id' => $academicYear->id,
                'program_id' => $bsit->id,
                'target_year_level_id' => $thirdYear->id,
            ],
            [
                'opens_at' => '2026-11-01 00:00:00',
                'closes_at' => '2026-12-15 23:59:59',
                'allow_changes' => true,
                'status' => 'active',
            ],
        );

        StudentMajorSelection::firstOrCreate(
            [
                'student_id' => $student->id,
                'academic_year_id' => $academicYear->id,
            ],
            [
                'program_id' => $bsit->id,
                'major_id' => $wmad->id,
                'period_id' => $selectionPeriod->id,
                'status' => 'approved',
                'selected_at' => '2026-11-05 10:00:00',
                'reviewed_by' => $admin->id,
                'reviewed_at' => '2026-11-06 10:00:00',
                'remarks' => 'Synthetic development-only major selection.',
            ],
        );

        $placement = StudentAcademicPlacement::firstOrCreate(
            [
                'student_id' => $student->id,
                'academic_term_id' => $currentTerm->id,
            ],
            [
                'academic_year_id' => $academicYear->id,
                'program_id' => $bsit->id,
                'year_level_id' => $firstYear->id,
                'major_id' => null,
                'section_id' => $section->id,
                'status' => 'active',
                'is_current' => true,
                'effective_on' => $currentTerm->starts_on,
                'notes' => 'Synthetic development-only placement.',
            ],
        );

        if ($student->current_placement_id === null || $student->section_id === null) {
            $student->update([
                'current_placement_id' => $placement->id,
                'section_id' => $section->id,
            ]);
        }

        $this->command?->info('Development accounts and synthetic academic demo data seeded without displaying passwords.');
    }
}
