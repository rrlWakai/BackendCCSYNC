<?php

namespace App\Policies;

use App\Models\Professor;
use App\Models\Role;
use App\Models\User;

final class ProfessorPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive()
            && $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }

    public function view(User $actor, Professor $professor): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL)
            || ($actor->hasRole(Role::PROFESSOR)
                && $professor->user_id === $actor->id);
    }

    public function updateOwn(User $actor, Professor $professor): bool
    {
        return $actor->isActive()
            && $actor->hasRole(Role::PROFESSOR)
            && $professor->user_id === $actor->id;
    }

    public function manageAvailability(User $actor, Professor $professor): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL)
            || ($actor->hasRole(Role::PROFESSOR)
                && $professor->user_id === $actor->id);
    }
}
