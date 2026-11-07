<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target);
    }

    public function changeStatus(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target);
    }

    public function manageProgramAccess(User $actor, User $target): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR)
            && $target->hasRole(Role::DEPARTMENT_PERSONNEL);
    }

    private function canManageTarget(User $actor, User $target): bool
    {
        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        return $actor->hasRole(Role::DEPARTMENT_PERSONNEL)
            && $target->hasRole(Role::PROFESSOR, Role::STUDENT);
    }
}
