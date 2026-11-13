<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\SubjectOffering;
use App\Models\User;

final class SubjectOfferingPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor, SubjectOffering $subjectOffering): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, SubjectOffering $subjectOffering): bool
    {
        return $this->canManage($actor);
    }

    public function changeStatus(User $actor, SubjectOffering $subjectOffering): bool
    {
        return $this->canManage($actor);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}
