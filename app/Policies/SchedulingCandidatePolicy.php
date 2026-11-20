<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\SchedulingCandidate;
use App\Models\User;

final class SchedulingCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }

    public function review(User $user, SchedulingCandidate $candidate): bool
    {
        return $this->canManage($user);
    }

    public function approve(User $user, SchedulingCandidate $candidate): bool
    {
        return $this->canManage($user);
    }

    public function reject(User $user, SchedulingCandidate $candidate): bool
    {
        return $this->canManage($user);
    }

    public function publish(User $user, SchedulingCandidate $candidate): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->isActive() && $user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}
