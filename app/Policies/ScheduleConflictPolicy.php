<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

final class ScheduleConflictPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function resolve(User $user): bool
    {
        return $this->canManage($user);
    }

    public function waive(User $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}
