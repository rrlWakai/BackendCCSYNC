<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

final class UserAccountPresenter
{
    public static function toArray(User $user, bool $includeReviewHistory = false): array
    {
        $user->loadMissing([
            'role',
            'student.program',
            'student.section.yearLevel',
            'professor.program',
            'professor.department',
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
                'year_level' => $user->student->section?->yearLevel === null ? null : [
                    'id' => $user->student->section->yearLevel->id,
                    'level' => $user->student->section->yearLevel->level,
                    'name' => $user->student->section->yearLevel->name,
                ],
                'section' => $user->student->section === null ? null : [
                    'id' => $user->student->section->id,
                    'code' => $user->student->section->code,
                    'name' => $user->student->section->name,
                ],
                'admitted_on' => $user->student->admitted_on?->toDateString(),
            ];
        } elseif ($user->professor !== null) {
            $profile = [
                'type' => 'professor',
                'employee_number' => $user->professor->employee_number,
                'employment_type' => $user->professor->employment_type,
                'specialization' => $user->professor->specialization,
                'department' => $user->professor->department === null ? null : [
                    'id' => $user->professor->department->id,
                    'code' => $user->professor->department->code,
                    'name' => $user->professor->department->name,
                ],
                'department_affiliation' => $user->professor->department === null
                    ? null
                    : ($user->professor->department->code === 'CCS' ? 'ccs' : 'other'),
                'program' => $user->professor->program === null ? null : [
                    'id' => $user->professor->program->id,
                    'code' => $user->professor->program->code,
                    'name' => $user->professor->program->name,
                ],
            ];
        }

        $result = [
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

        if ($includeReviewHistory && $user->hasRole(Role::PROFESSOR)) {
            $user->loadMissing('professorRegistrationReviews.actor:id,name');
            $result['review_history'] = $user->professorRegistrationReviews
                ->sortBy('created_at')
                ->map(fn ($review): array => [
                    'id' => $review->id,
                    'action' => $review->action,
                    'reason' => $review->reason,
                    'registration' => $review->registration_snapshot,
                    'actor' => [
                        'id' => $review->actor->id,
                        'name' => $review->actor->name,
                    ],
                    'created_at' => $review->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $result;
    }
}
