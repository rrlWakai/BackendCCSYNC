<?php

namespace Database\Seeders;

use App\Models\Role;
use Database\Seeders\Concerns\RefuseProtectedDatabase;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    use RefuseProtectedDatabase;

    public function run(): void
    {
        $this->refuseProtectedDatabase();

        $roles = [
            [
                'code' => Role::ADMINISTRATOR,
                'name' => 'Administrator',
                'description' => 'Full system administration and role management.',
            ],
            [
                'code' => Role::DEPARTMENT_PERSONNEL,
                'name' => 'Department Personnel',
                'description' => 'Academic operations without role or administrator management.',
            ],
            [
                'code' => Role::PROFESSOR,
                'name' => 'Professor',
                'description' => 'Faculty workspace for assigned offerings and schedules.',
            ],
            [
                'code' => Role::STUDENT,
                'name' => 'Student',
                'description' => 'Student workspace for enrollment and schedules.',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }
    }
}
