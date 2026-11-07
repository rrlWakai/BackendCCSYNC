<?php

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Role;
use App\Models\User;

final class AttendanceSessionPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        if ($user->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            return $user->departmentPersonnelPrograms()->exists();
        }

        return $this->activeProfessor($user);
    }

    public function create(User $user, ClassSchedule $schedule): bool
    {
        if (! $this->activeProfessor($user) || $schedule->status !== 'published') {
            return false;
        }

        return $schedule->scheduleAssignments()
            ->where('status', 'active')
            ->whereHas('facultyAssignment', fn ($query) => $query
                ->where('professor_id', $user->professor->id)
                ->where('status', 'active'))
            ->exists();
    }

    public function view(User $user, AttendanceSession $session): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        if ($user->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            return $user->departmentPersonnelPrograms()
                ->whereKey($session->classSchedule->subjectOffering->section->program_id)
                ->exists();
        }

        return $this->professorOwnsSchedule($user, $session->classSchedule);
    }

    public function updateStatus(User $user, AttendanceSession $session): bool
    {
        return $user->isActive()
            && ($user->hasRole(Role::ADMINISTRATOR)
                || ($user->hasRole(Role::PROFESSOR)
                    && $this->professorOwnsSchedule($user, $session->classSchedule)));
    }

    public function record(User $user, AttendanceSession $session): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole(Role::ADMINISTRATOR)) {
            return $session->status === 'submitted';
        }

        return $user->hasRole(Role::PROFESSOR)
            && $this->professorOwnsSchedule($user, $session->classSchedule);
    }

    public function manageQr(User $user, AttendanceSession $session): bool
    {
        return $user->isActive()
            && $user->hasRole(Role::PROFESSOR)
            && $this->professorOwnsSchedule($user, $session->classSchedule);
    }

    private function activeProfessor(User $user): bool
    {
        return $user->hasRole(Role::PROFESSOR)
            && $user->professor !== null
            && $user->professor->status === 'active';
    }

    private function professorOwnsSchedule(User $user, ClassSchedule $schedule): bool
    {
        $professor = $user->professor;

        return $user->hasRole(Role::PROFESSOR)
            && $professor !== null
            && $professor->status === 'active'
            && $schedule->scheduleAssignments()
                ->whereHas('facultyAssignment', fn ($query) => $query
                    ->where('professor_id', $professor->id))
                ->exists();
    }
}
