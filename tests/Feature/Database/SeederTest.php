<?php

namespace Tests\Feature\Database;

use App\Models\Professor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Database\Seeders\DevelopmentAccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seeders_create_the_four_accounts_and_profiles(): void
    {
        $this->seed([
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DevelopmentAccountSeeder::class,
        ]);

        $this->assertSame(4, Role::count());
        $this->assertDatabaseHas('users', ['email' => 'admin@ccsync.test', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'personnel@ccsync.test', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'professor@ccsync.test', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'student@ccsync.test', 'status' => 'active']);

        $professorUser = User::where('email', 'professor@ccsync.test')->firstOrFail();
        $studentUser = User::where('email', 'student@ccsync.test')->firstOrFail();

        $this->assertSame('professor', $professorUser->role->code);
        $this->assertSame('student', $studentUser->role->code);
        $this->assertInstanceOf(Professor::class, $professorUser->professor);
        $this->assertInstanceOf(Student::class, $studentUser->student);
        $this->assertSame(1, StudentSubjectEnrollment::where('student_id', $studentUser->student->id)->count());
        $this->assertSame('2026-0001', $studentUser->student->student_number);
        $this->assertSame('EMP-0001', $professorUser->professor->employee_number);
    }

    public function test_development_account_seeder_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to create development accounts in production.');

        (new DevelopmentAccountSeeder)->run();
    }

    public function test_seeders_refuse_to_modify_the_populated_project_database(): void
    {
        config(['database.connections.mysql.database' => 'ccsync_db']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run seeders against the protected ccsync_db database.');

        (new DatabaseSeeder)->run();
    }

    public function test_direct_reference_data_seeding_also_refuses_the_project_database(): void
    {
        config(['database.connections.mysql.database' => 'ccsync_db']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run seeders against the protected ccsync_db database.');

        (new ReferenceDataSeeder)->run();
    }
}
