<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

final class UserPresenter
{
    public static function toArray(User $user): array
    {
        $user->loadMissing([
            'role',
            'student.program',
            'student.currentPlacement.academicYear',
            'student.currentPlacement.yearLevel',
            'student.currentPlacement.major',
            'student.currentPlacement.section',
            'professor.program',
        ]);

        $role = $user->role;
        $profile = null;

        if ($role?->code === Role::STUDENT && $user->student !== null) {
            $placement = $user->student->currentPlacement;

            $profile = [
                'type' => 'student',
                'studentNumber' => $user->student->student_number,
                'program' => $user->student->program === null ? null : [
                    'code' => $user->student->program->code,
                    'name' => $user->student->program->name,
                ],
                'admittedOn' => $user->student->admitted_on?->toDateString(),
                'academicYear' => $placement?->academicYear?->label,
                'yearLevel' => $placement?->yearLevel === null ? null : [
                    'level' => $placement->yearLevel->level,
                    'name' => $placement->yearLevel->name,
                ],
                'major' => $placement?->major === null ? null : [
                    'code' => $placement->major->code,
                    'name' => $placement->major->name,
                ],
                'section' => $placement?->section === null ? null : [
                    'code' => $placement->section->code,
                    'name' => $placement->section->name,
                ],
            ];
        }

        if ($role?->code === Role::PROFESSOR && $user->professor !== null) {
            $profile = [
                'type' => 'professor',
                'employeeNumber' => $user->professor->employee_number,
                'employmentType' => $user->professor->employment_type,
                'specialization' => $user->professor->specialization,
                'program' => $user->professor->program === null ? null : [
                    'code' => $user->professor->program->code,
                    'name' => $user->professor->program->name,
                ],
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role?->code,
            'roleLabel' => $role?->name,
            'status' => $user->status,
            'profile' => $profile,
        ];
    }
}
