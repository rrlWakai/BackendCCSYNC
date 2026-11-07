<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;

final class StudentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }

    public function view(User $actor, Student $student): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        if ($actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL)) {
            return true;
        }

        if ($actor->hasRole(Role::STUDENT)) {
            return $student->user_id === $actor->id;
        }

        if (! $actor->hasRole(Role::PROFESSOR) || $actor->professor === null) {
            return false;
        }

        return $student->enrollments()
            ->whereHas('subjectOffering.facultyAssignments', fn ($query) => $query
                ->where('professor_id', $actor->professor->id)
                ->where('status', 'active'))
            ->exists();
    }
}
