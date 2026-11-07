<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

final class UserAccountPresenter
{
    public static function toArray(User $user): array
    {
        $user->loadMissing([
            'role',
            'student.program',
            'professor.program',
            'departmentPersonnelPrograms:id,code,name,status',
        ]);

        $profile = null;

        if ($user->student !== null) {
            $profile = [
                'type' => 'student',
                'student_number' => $user->student->student_number,
                'program' => $user->student->program === null ? null : [
                    'id' => $user->student->program->id,
                    'code' => $user->student->program->code,
                    'name' => $user->student->program->name,
                ],
                'admitted_on' => $user->student->admitted_on?->toDateString(),
            ];
        } elseif ($user->professor !== null) {
            $profile = [
                'type' => 'professor',
                'employee_number' => $user->professor->employee_number,
                'employment_type' => $user->professor->employment_type,
                'specialization' => $user->professor->specialization,
                'program' => $user->professor->program === null ? null : [
                    'id' => $user->professor->program->id,
                    'code' => $user->professor->program->code,
                    'name' => $user->professor->program->name,
                ],
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => [
                'code' => $user->role?->code,
                'name' => $user->role?->name,
            ],
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'profile' => $profile,
            'program_access' => $user->hasRole(Role::DEPARTMENT_PERSONNEL)
                ? $user->departmentPersonnelPrograms->map(fn ($program): array => [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                    'status' => $program->status,
                ])->values()->all()
                : [],
        ];
    }
}
