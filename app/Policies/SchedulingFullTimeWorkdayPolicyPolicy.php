<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\SchedulingFullTimeWorkdayPolicy;
use App\Models\User;

final class SchedulingFullTimeWorkdayPolicyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor, SchedulingFullTimeWorkdayPolicy $policy): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, SchedulingFullTimeWorkdayPolicy $policy): bool
    {
        return $this->canManage($actor);
    }

    public function delete(User $actor, SchedulingFullTimeWorkdayPolicy $policy): bool
    {
        return $this->canManage($actor);
    }

    public function approve(User $actor, SchedulingFullTimeWorkdayPolicy $policy): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR);
    }

    public function revise(User $actor, SchedulingFullTimeWorkdayPolicy $policy): bool
    {
        return $this->canManage($actor);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}
