<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\StudentAttendance;
use App\Models\User;

final class StudentAttendancePolicy
{
    public function viewOwn(User $user): bool
    {
        return $user->isActive()
            && $user->hasRole(Role::STUDENT)
            && $user->student !== null
            && $user->student->status === 'active';
    }

    public function checkIn(User $user): bool
    {
        return $this->viewOwn($user);
    }

    public function view(User $user, StudentAttendance $attendance): bool
    {
        return $this->viewOwn($user)
            && $attendance->enrollment?->student?->user_id === $user->id;
    }
}
