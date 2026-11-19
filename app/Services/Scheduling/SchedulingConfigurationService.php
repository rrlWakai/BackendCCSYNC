<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Professor;
use App\Models\SchedulingFullTimeWorkdayException;
use App\Models\SchedulingFullTimeWorkdayPolicy;
use App\Models\SchedulingMeetingPlan;
use App\Models\SchedulingMeetingRequirement;
use App\Models\SchedulingTimeGrid;
use App\Models\SchedulingTimeGridDay;
use App\Models\SchedulingTimeGridSlot;
use App\Models\SubjectOffering;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SchedulingConfigurationService
{
    private const MEETING_TYPES = ['lecture', 'laboratory', 'seminar', 'other'];

    private const MODALITIES = ['face_to_face', 'online', 'hybrid'];

    private const ROOM_TYPES = ['lecture', 'laboratory', 'seminar', 'office', 'other'];

    /**
     * @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>}
     */
    public function timeGridIndex(AcademicTerm $term): array
    {
        $grids = SchedulingTimeGrid::query()
            ->where('academic_term_id', $term->id)
            ->with(['days.slots', 'approver:id,name'])
            ->orderByDesc('version')
            ->get();

        $current = $grids->firstWhere('status', SchedulingTimeGrid::STATUS_DRAFT)
            ?? $grids->firstWhere('status', SchedulingTimeGrid::STATUS_APPROVED);

        return [
            'current' => $current === null ? null : $this->presentTimeGrid($current),
            'history' => $grids->map(fn (SchedulingTimeGrid $grid): array => $this->presentTimeGrid($grid))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTimeGridDraft(AcademicTerm $term, array $attributes, User $actor): SchedulingTimeGrid
    {
        return DB::transaction(function () use ($term, $attributes): SchedulingTimeGrid {
            SchedulingTimeGrid::query()
                ->where('academic_term_id', $term->id)
                ->lockForUpdate()
                ->get(['id']);

            $this->ensureNoDraftTimeGrid($term);

            $grid = SchedulingTimeGrid::create([
                'academic_term_id' => $term->id,
                'version' => $this->nextTimeGridVersion($term),
                'status' => SchedulingTimeGrid::STATUS_DRAFT,
                'timezone' => $attributes['timezone'],
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->replaceTimeGridDays($grid, $attributes['days']);

            return $grid->fresh(['days.slots', 'approver:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTimeGridDraft(SchedulingTimeGrid $grid, array $attributes): SchedulingTimeGrid
    {
        $this->ensureTimeGridDraft($grid);

        return DB::transaction(function () use ($grid, $attributes): SchedulingTimeGrid {
            $grid->update([
                'timezone' => $attributes['timezone'],
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->replaceTimeGridDays($grid, $attributes['days']);

            return $grid->fresh(['days.slots', 'approver:id,name']);
        });
    }

    public function deleteTimeGridDraft(SchedulingTimeGrid $grid): void
    {
        $this->ensureTimeGridDraft($grid);
        $grid->delete();
    }

    public function approveTimeGrid(SchedulingTimeGrid $grid, User $actor): SchedulingTimeGrid
    {
        $this->ensureTimeGridDraft($grid);
        $this->validateTimeGridForApproval($grid);

        return DB::transaction(function () use ($grid, $actor): SchedulingTimeGrid {
            SchedulingTimeGrid::query()
                ->where('academic_term_id', $grid->academic_term_id)
                ->lockForUpdate()
                ->get(['id']);

            SchedulingTimeGrid::query()
                ->where('academic_term_id', $grid->academic_term_id)
                ->where('status', SchedulingTimeGrid::STATUS_APPROVED)
                ->update(['status' => SchedulingTimeGrid::STATUS_SUPERSEDED]);

            $grid->update([
                'status' => SchedulingTimeGrid::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            return $grid->fresh(['days.slots', 'approver:id,name']);
        });
    }

    public function reviseTimeGrid(SchedulingTimeGrid $grid): SchedulingTimeGrid
    {
        return DB::transaction(function () use ($grid): SchedulingTimeGrid {
            SchedulingTimeGrid::query()
                ->where('academic_term_id', $grid->academic_term_id)
                ->lockForUpdate()
                ->get(['id']);

            $this->ensureNoDraftTimeGrid($grid->academicTerm);

            $draft = SchedulingTimeGrid::create([
                'academic_term_id' => $grid->academic_term_id,
                'version' => $this->nextTimeGridVersion($grid->academicTerm),
                'status' => SchedulingTimeGrid::STATUS_DRAFT,
                'timezone' => $grid->timezone,
                'notes' => $grid->notes,
            ]);

            $grid->loadMissing('days.slots');

            foreach ($grid->days as $day) {
                $draftDay = $draft->days()->create([
                    'day_of_week' => $day->day_of_week,
                    'operating_start' => $day->operating_start,
                    'operating_end' => $day->operating_end,
                ]);

                foreach ($day->slots as $slot) {
                    $draftDay->slots()->create([
                        'start_time' => $slot->start_time,
                        'end_time' => $slot->end_time,
                        'label' => $slot->label,
                        'sort_order' => $slot->sort_order,
                    ]);
                }
            }

            return $draft->fresh(['days.slots', 'approver:id,name']);
        });
    }

    /**
     * @return array{plans: list<array<string, mixed>>, offerings: list<array<string, mixed>>}
     */
    public function meetingPlanIndex(AcademicTerm $term): array
    {
        $offerings = SubjectOffering::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'open')
            ->with([
                'subject:id,code,title,lecture_hours,lab_hours',
                'section:id,code,name',
            ])
            ->orderBy('id')
            ->get();

        $plans = SchedulingMeetingPlan::query()
            ->where('academic_term_id', $term->id)
            ->with(['requirements', 'approver:id,name'])
            ->orderByDesc('version')
            ->get()
            ->groupBy('subject_offering_id');

        $current = [];
        $history = [];

        foreach ($plans as $offeringPlans) {
            $currentPlan = $offeringPlans->firstWhere('status', SchedulingMeetingPlan::STATUS_DRAFT)
                ?? $offeringPlans->firstWhere('status', SchedulingMeetingPlan::STATUS_APPROVED);

            if ($currentPlan !== null) {
                $current[(int) $currentPlan->subject_offering_id] = $this->presentMeetingPlan($currentPlan);
            }

            foreach ($offeringPlans as $plan) {
                $history[] = $this->presentMeetingPlan($plan);
            }
        }

        return [
            'plans' => $offerings
                ->map(fn (SubjectOffering $offering): array => [
                    'offering' => $this->presentOffering($offering),
                    'current' => $current[$offering->id] ?? null,
                    'history' => array_values(array_filter(
                        $history,
                        fn (array $plan): bool => (int) $plan['subject_offering_id'] === $offering->id,
                    )),
                ])
                ->values()
                ->all(),
            'offerings' => $offerings->map(fn (SubjectOffering $offering): array => $this->presentOffering($offering))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMeetingPlanDraft(SubjectOffering $offering, array $attributes, User $actor): SchedulingMeetingPlan
    {
        return DB::transaction(function () use ($offering, $attributes): SchedulingMeetingPlan {
            SchedulingMeetingPlan::query()
                ->where('subject_offering_id', $offering->id)
                ->lockForUpdate()
                ->get(['id']);

            $this->ensureNoDraftMeetingPlan($offering);
            $this->ensureNoPublishedScheduleForOffering($offering);

            $plan = SchedulingMeetingPlan::create([
                'academic_term_id' => $offering->academic_term_id,
                'subject_offering_id' => $offering->id,
                'version' => $this->nextMeetingPlanVersion($offering),
                'status' => SchedulingMeetingPlan::STATUS_DRAFT,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->replaceMeetingRequirements($plan, $attributes['requirements']);

            return $plan->fresh(['requirements', 'approver:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMeetingPlanDraft(SchedulingMeetingPlan $plan, array $attributes): SchedulingMeetingPlan
    {
        $this->ensureMeetingPlanDraft($plan);
        $this->ensureNoPublishedScheduleForOffering($plan->subjectOffering);

        return DB::transaction(function () use ($plan, $attributes): SchedulingMeetingPlan {
            $plan->update(['notes' => $attributes['notes'] ?? null]);
            $this->replaceMeetingRequirements($plan, $attributes['requirements']);

            return $plan->fresh(['requirements', 'approver:id,name']);
        });
    }

    public function deleteMeetingPlanDraft(SchedulingMeetingPlan $plan): void
    {
        $this->ensureMeetingPlanDraft($plan);
        $plan->delete();
    }

    public function approveMeetingPlan(SchedulingMeetingPlan $plan, User $actor): SchedulingMeetingPlan
    {
        $this->ensureMeetingPlanDraft($plan);
        $this->ensureNoPublishedScheduleForOffering($plan->subjectOffering);
        $this->validateMeetingPlanForApproval($plan);

        return DB::transaction(function () use ($plan, $actor): SchedulingMeetingPlan {
            SchedulingMeetingPlan::query()
                ->where('subject_offering_id', $plan->subject_offering_id)
                ->lockForUpdate()
                ->get(['id']);

            SchedulingMeetingPlan::query()
                ->where('subject_offering_id', $plan->subject_offering_id)
                ->where('status', SchedulingMeetingPlan::STATUS_APPROVED)
                ->update(['status' => SchedulingMeetingPlan::STATUS_SUPERSEDED]);

            $plan->update([
                'status' => SchedulingMeetingPlan::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            return $plan->fresh(['requirements', 'approver:id,name']);
        });
    }

    public function reviseMeetingPlan(SchedulingMeetingPlan $plan): SchedulingMeetingPlan
    {
        return DB::transaction(function () use ($plan): SchedulingMeetingPlan {
            SchedulingMeetingPlan::query()
                ->where('subject_offering_id', $plan->subject_offering_id)
                ->lockForUpdate()
                ->get(['id']);

            $offering = $plan->subjectOffering;
            $this->ensureNoDraftMeetingPlan($offering);
            $this->ensureNoPublishedScheduleForOffering($offering);

            $draft = SchedulingMeetingPlan::create([
                'academic_term_id' => $plan->academic_term_id,
                'subject_offering_id' => $plan->subject_offering_id,
                'version' => $this->nextMeetingPlanVersion($offering),
                'status' => SchedulingMeetingPlan::STATUS_DRAFT,
                'notes' => $plan->notes,
            ]);

            $plan->loadMissing('requirements');

            foreach ($plan->requirements as $requirement) {
                $draft->requirements()->create([
                    'meeting_type' => $requirement->meeting_type,
                    'frequency_per_week' => $requirement->frequency_per_week,
                    'duration_minutes' => $requirement->duration_minutes,
                    'modality' => $requirement->modality,
                    'required_room_type' => $requirement->required_room_type,
                    'mandatory' => $requirement->mandatory,
                    'allowed_days' => $requirement->allowed_days,
                    'valid_start_times' => $requirement->valid_start_times,
                    'sort_order' => $requirement->sort_order,
                ]);
            }

            return $draft->fresh(['requirements', 'approver:id,name']);
        });
    }

    /**
     * @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>}
     */
    public function fullTimePolicyIndex(AcademicTerm $term): array
    {
        $policies = SchedulingFullTimeWorkdayPolicy::query()
            ->where('academic_term_id', $term->id)
            ->with(['exceptions', 'approver:id,name'])
            ->orderByDesc('version')
            ->get();

        $current = $policies->firstWhere('status', SchedulingFullTimeWorkdayPolicy::STATUS_DRAFT)
            ?? $policies->firstWhere('status', SchedulingFullTimeWorkdayPolicy::STATUS_APPROVED);

        return [
            'current' => $current === null ? null : $this->presentFullTimePolicy($current),
            'history' => $policies
                ->map(fn (SchedulingFullTimeWorkdayPolicy $policy): array => $this->presentFullTimePolicy($policy))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createFullTimePolicyDraft(AcademicTerm $term, array $attributes, User $actor): SchedulingFullTimeWorkdayPolicy
    {
        return DB::transaction(function () use ($term, $attributes): SchedulingFullTimeWorkdayPolicy {
            SchedulingFullTimeWorkdayPolicy::query()
                ->where('academic_term_id', $term->id)
                ->lockForUpdate()
                ->get(['id']);

            $this->ensureNoDraftFullTimePolicy($term);
            $this->validateFullTimeProfessorExceptions($attributes['exceptions'] ?? []);

            $policy = SchedulingFullTimeWorkdayPolicy::create([
                'academic_term_id' => $term->id,
                'version' => $this->nextFullTimePolicyVersion($term),
                'status' => SchedulingFullTimeWorkdayPolicy::STATUS_DRAFT,
                'standard_days' => $this->sortedInts(array_map(fn ($day): int => (int) $day, $attributes['standard_days'])),
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->replaceFullTimeExceptions($policy, $attributes['exceptions'] ?? []);

            return $policy->fresh(['exceptions', 'approver:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateFullTimePolicyDraft(
        SchedulingFullTimeWorkdayPolicy $policy,
        array $attributes,
    ): SchedulingFullTimeWorkdayPolicy {
        $this->ensureFullTimePolicyDraft($policy);

        return DB::transaction(function () use ($policy, $attributes): SchedulingFullTimeWorkdayPolicy {
            $this->validateFullTimeProfessorExceptions($attributes['exceptions'] ?? []);

            $policy->update([
                'standard_days' => $this->sortedInts(array_map(fn ($day): int => (int) $day, $attributes['standard_days'])),
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->replaceFullTimeExceptions($policy, $attributes['exceptions'] ?? []);

            return $policy->fresh(['exceptions', 'approver:id,name']);
        });
    }

    public function deleteFullTimePolicyDraft(SchedulingFullTimeWorkdayPolicy $policy): void
    {
        $this->ensureFullTimePolicyDraft($policy);
        $policy->delete();
    }

    public function approveFullTimePolicy(
        SchedulingFullTimeWorkdayPolicy $policy,
        User $actor,
    ): SchedulingFullTimeWorkdayPolicy {
        $this->ensureFullTimePolicyDraft($policy);
        $this->validateFullTimePolicyForApproval($policy);

        return DB::transaction(function () use ($policy, $actor): SchedulingFullTimeWorkdayPolicy {
            SchedulingFullTimeWorkdayPolicy::query()
                ->where('academic_term_id', $policy->academic_term_id)
                ->lockForUpdate()
                ->get(['id']);

            SchedulingFullTimeWorkdayPolicy::query()
                ->where('academic_term_id', $policy->academic_term_id)
                ->where('status', SchedulingFullTimeWorkdayPolicy::STATUS_APPROVED)
                ->update(['status' => SchedulingFullTimeWorkdayPolicy::STATUS_SUPERSEDED]);

            $policy->update([
                'status' => SchedulingFullTimeWorkdayPolicy::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            return $policy->fresh(['exceptions', 'approver:id,name']);
        });
    }

    public function reviseFullTimePolicy(SchedulingFullTimeWorkdayPolicy $policy): SchedulingFullTimeWorkdayPolicy
    {
        return DB::transaction(function () use ($policy): SchedulingFullTimeWorkdayPolicy {
            SchedulingFullTimeWorkdayPolicy::query()
                ->where('academic_term_id', $policy->academic_term_id)
                ->lockForUpdate()
                ->get(['id']);

            $term = $policy->academicTerm;
            $this->ensureNoDraftFullTimePolicy($term);

            $draft = SchedulingFullTimeWorkdayPolicy::create([
                'academic_term_id' => $policy->academic_term_id,
                'version' => $this->nextFullTimePolicyVersion($term),
                'status' => SchedulingFullTimeWorkdayPolicy::STATUS_DRAFT,
                'standard_days' => $policy->standard_days,
                'notes' => $policy->notes,
            ]);

            $policy->loadMissing('exceptions');

            foreach ($policy->exceptions as $exception) {
                $draft->exceptions()->create([
                    'professor_id' => $exception->professor_id,
                    'authorized_additional_days' => $exception->authorized_additional_days,
                ]);
            }

            return $draft->fresh(['exceptions', 'approver:id,name']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFullTimePolicy(SchedulingFullTimeWorkdayPolicy $policy): array
    {
        $policy->loadMissing(['exceptions', 'approver:id,name']);

        return [
            'id' => $policy->id,
            'academic_term_id' => $policy->academic_term_id,
            'version' => $policy->version,
            'status' => $policy->status,
            'standard_days' => $policy->standard_days,
            'approved_at' => $policy->approved_at?->toIso8601String(),
            'approved_by' => $policy->approver === null ? null : [
                'id' => $policy->approver->id,
                'name' => $policy->approver->name,
            ],
            'notes' => $policy->notes,
            'exceptions' => $policy->exceptions
                ->map(fn (SchedulingFullTimeWorkdayException $exception): array => [
                    'id' => $exception->id,
                    'professor_id' => $exception->professor_id,
                    'authorized_additional_days' => $exception->authorized_additional_days,
                ])
                ->values()
                ->all(),
            'created_at' => $policy->created_at?->toIso8601String(),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTimeGrid(SchedulingTimeGrid $grid): array
    {
        $grid->loadMissing(['days.slots', 'approver:id,name']);

        return [
            'id' => $grid->id,
            'academic_term_id' => $grid->academic_term_id,
            'version' => $grid->version,
            'status' => $grid->status,
            'timezone' => $grid->timezone,
            'approved_at' => $grid->approved_at?->toIso8601String(),
            'approved_by' => $grid->approver === null ? null : [
                'id' => $grid->approver->id,
                'name' => $grid->approver->name,
            ],
            'notes' => $grid->notes,
            'days' => $grid->days->map(fn (SchedulingTimeGridDay $day): array => [
                'id' => $day->id,
                'day_of_week' => $day->day_of_week,
                'operating_start' => substr((string) $day->operating_start, 0, 5),
                'operating_end' => substr((string) $day->operating_end, 0, 5),
                'slots' => $day->slots->map(fn (SchedulingTimeGridSlot $slot): array => [
                    'id' => $slot->id,
                    'start_time' => substr((string) $slot->start_time, 0, 5),
                    'end_time' => substr((string) $slot->end_time, 0, 5),
                    'label' => $slot->label,
                ])->values()->all(),
            ])->values()->all(),
            'created_at' => $grid->created_at?->toIso8601String(),
            'updated_at' => $grid->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMeetingPlan(SchedulingMeetingPlan $plan): array
    {
        $plan->loadMissing(['requirements', 'approver:id,name']);

        return [
            'id' => $plan->id,
            'academic_term_id' => $plan->academic_term_id,
            'subject_offering_id' => $plan->subject_offering_id,
            'version' => $plan->version,
            'status' => $plan->status,
            'approved_at' => $plan->approved_at?->toIso8601String(),
            'approved_by' => $plan->approver === null ? null : [
                'id' => $plan->approver->id,
                'name' => $plan->approver->name,
            ],
            'notes' => $plan->notes,
            'requirements' => $plan->requirements->map(
                fn (SchedulingMeetingRequirement $requirement): array => [
                    'id' => $requirement->id,
                    'meeting_type' => $requirement->meeting_type,
                    'frequency_per_week' => $requirement->frequency_per_week,
                    'duration_minutes' => $requirement->duration_minutes,
                    'modality' => $requirement->modality,
                    'required_room_type' => $requirement->required_room_type,
                    'mandatory' => $requirement->mandatory,
                    'allowed_days' => $requirement->allowed_days,
                    'valid_start_times' => $requirement->valid_start_times,
                ],
            )->values()->all(),
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $exceptions
     */
    private function validateFullTimeProfessorExceptions(array $exceptions): void
    {
        $professorIds = array_values(array_unique(array_map(
            fn (array $exception): int => (int) $exception['professor_id'],
            $exceptions,
        )));

        if ($professorIds === []) {
            return;
        }

        $eligibleIds = Professor::query()
            ->whereIn('id', $professorIds)
            ->where('employment_type', 'full_time')
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($eligibleIds) !== count($professorIds)) {
            throw ValidationException::withMessages([
                'exceptions' => 'Every exception must reference an active full-time professor.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $exceptions
     */
    private function replaceFullTimeExceptions(
        SchedulingFullTimeWorkdayPolicy $policy,
        array $exceptions,
    ): void {
        $policy->exceptions()->delete();

        foreach ($exceptions as $exception) {
            $policy->exceptions()->create([
                'professor_id' => (int) $exception['professor_id'],
                'authorized_additional_days' => $this->sortedInts(array_map(
                    fn ($day): int => (int) $day,
                    $exception['authorized_additional_days'],
                )),
            ]);
        }
    }

    private function ensureNoDraftFullTimePolicy(AcademicTerm $term): void
    {
        $exists = SchedulingFullTimeWorkdayPolicy::query()
            ->where('academic_term_id', $term->id)
            ->where('status', SchedulingFullTimeWorkdayPolicy::STATUS_DRAFT)
            ->exists();

        if ($exists) {
            throw new ConflictHttpException('A draft full-time workday policy already exists for this academic term. Edit or discard it before creating another.');
        }
    }

    private function ensureFullTimePolicyDraft(SchedulingFullTimeWorkdayPolicy $policy): void
    {
        if ($policy->status !== SchedulingFullTimeWorkdayPolicy::STATUS_DRAFT) {
            throw new ConflictHttpException('Approved full-time workday policies are immutable. Create a revision instead.');
        }
    }

    private function nextFullTimePolicyVersion(AcademicTerm $term): int
    {
        return ((int) SchedulingFullTimeWorkdayPolicy::query()
            ->where('academic_term_id', $term->id)
            ->max('version')) + 1;
    }

    private function validateFullTimePolicyForApproval(SchedulingFullTimeWorkdayPolicy $policy): void
    {
        $policy->loadMissing('exceptions');
        $errors = [];
        $standardDays = collect($policy->standard_days)
            ->map(fn ($day): int => (int) $day)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($standardDays === []
            || $standardDays !== array_values(array_unique($standardDays))
            || collect($standardDays)->contains(fn (int $day): bool => $day < 1 || $day > 5)) {
            $errors['standard_days'] = 'Standard days must be a nonempty sorted unique list of weekdays 1 through 5.';
        }

        $professorIds = [];

        foreach ($policy->exceptions as $exception) {
            $professorIds[] = (int) $exception->professor_id;
            $days = collect($exception->authorized_additional_days)
                ->map(fn ($day): int => (int) $day)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($days === []
                || $days !== array_values(array_unique($days))
                || collect($days)->contains(fn (int $day): bool => $day < 1 || $day > 5)) {
                $errors['exceptions'] = 'Authorized additional days must be nonempty sorted unique weekdays 1 through 5.';
            }

            if (array_intersect($days, $standardDays) !== []) {
                $errors['exceptions'] = 'Authorized additional days must not be part of the standard workweek.';
            }
        }

        if (count($professorIds) !== count(array_unique($professorIds))) {
            $errors['exceptions'] = 'Each professor can have only one workday exception.';
        }

        if ($professorIds !== []) {
            $eligibleIds = Professor::query()
                ->whereIn('id', $professorIds)
                ->where('employment_type', 'full_time')
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($eligibleIds) !== count(array_unique($professorIds))) {
                $errors['exceptions'] = 'Every exception must reference an active full-time professor.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function replaceTimeGridDays(SchedulingTimeGrid $grid, array $days): void
    {
        $grid->days()->delete();

        foreach ($days as $day) {
            $gridDay = $grid->days()->create([
                'day_of_week' => (int) $day['day_of_week'],
                'operating_start' => $day['operating_start'],
                'operating_end' => $day['operating_end'],
            ]);

            $sortOrder = 0;
            $slots = $day['slots'];
            usort($slots, fn (array $left, array $right): int => $left['start_time'] <=> $right['start_time']);

            foreach ($slots as $slot) {
                $gridDay->slots()->create([
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'label' => $slot['label'] ?? null,
                    'sort_order' => $sortOrder,
                ]);

                $sortOrder++;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function replaceMeetingRequirements(SchedulingMeetingPlan $plan, array $requirements): void
    {
        $plan->requirements()->delete();

        $sortOrder = 0;

        foreach ($requirements as $requirement) {
            $plan->requirements()->create([
                'meeting_type' => $requirement['meeting_type'],
                'frequency_per_week' => (int) $requirement['frequency_per_week'],
                'duration_minutes' => (int) $requirement['duration_minutes'],
                'modality' => $requirement['modality'],
                'required_room_type' => $requirement['required_room_type'] ?? null,
                'mandatory' => (bool) ($requirement['mandatory'] ?? true),
                'allowed_days' => $this->sortedNullableIntList($requirement['allowed_days'] ?? null),
                'valid_start_times' => $this->sortedNullableStringList($requirement['valid_start_times'] ?? null),
                'sort_order' => $sortOrder,
            ]);

            $sortOrder++;
        }
    }

    private function ensureNoDraftTimeGrid(AcademicTerm $term): void
    {
        $exists = SchedulingTimeGrid::query()
            ->where('academic_term_id', $term->id)
            ->where('status', SchedulingTimeGrid::STATUS_DRAFT)
            ->exists();

        if ($exists) {
            throw new ConflictHttpException('A draft time grid already exists for this academic term. Edit or discard it before creating another.');
        }
    }

    private function ensureNoDraftMeetingPlan(SubjectOffering $offering): void
    {
        $exists = SchedulingMeetingPlan::query()
            ->where('subject_offering_id', $offering->id)
            ->where('status', SchedulingMeetingPlan::STATUS_DRAFT)
            ->exists();

        if ($exists) {
            throw new ConflictHttpException('A draft meeting plan already exists for this class assignment. Edit or discard it before creating another.');
        }
    }

    private function ensureNoPublishedScheduleForOffering(SubjectOffering $offering): void
    {
        $published = ClassSchedule::query()
            ->where('subject_offering_id', $offering->id)
            ->where('status', 'published')
            ->exists();

        if ($published) {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'Meeting plans cannot be changed while this class assignment has a published schedule. Do not silently alter the configuration behind a published result.',
            ]);
        }
    }

    private function ensureTimeGridDraft(SchedulingTimeGrid $grid): void
    {
        if ($grid->status !== SchedulingTimeGrid::STATUS_DRAFT) {
            throw new ConflictHttpException('Approved time grids are immutable. Create a revision instead.');
        }
    }

    private function ensureMeetingPlanDraft(SchedulingMeetingPlan $plan): void
    {
        if ($plan->status !== SchedulingMeetingPlan::STATUS_DRAFT) {
            throw new ConflictHttpException('Approved meeting plans are immutable. Create a revision instead.');
        }
    }

    private function nextTimeGridVersion(AcademicTerm $term): int
    {
        return ((int) SchedulingTimeGrid::query()
            ->where('academic_term_id', $term->id)
            ->max('version')) + 1;
    }

    private function nextMeetingPlanVersion(SubjectOffering $offering): int
    {
        return ((int) SchedulingMeetingPlan::query()
            ->where('subject_offering_id', $offering->id)
            ->max('version')) + 1;
    }

    private function validateTimeGridForApproval(SchedulingTimeGrid $grid): void
    {
        $grid->loadMissing('days.slots');
        $errors = [];

        try {
            new DateTimeZone($grid->timezone);
        } catch (\Throwable) {
            $errors['timezone'] = 'The timezone must be a recognized IANA timezone.';
        }

        if ($grid->days->isEmpty()) {
            $errors['days'] = 'At least one Monday-to-Friday day with fixed slots is required.';
        }

        foreach ($grid->days as $day) {
            $prefix = 'days.'.$day->day_of_week;
            $start = substr((string) $day->operating_start, 0, 5);
            $end = substr((string) $day->operating_end, 0, 5);

            if ($day->day_of_week < 1 || $day->day_of_week > 5) {
                $errors[$prefix] = 'Only Monday through Friday are supported.';
            }

            if ($start >= $end) {
                $errors[$prefix.'.operating_hours'] = 'Operating start must precede operating end.';
            }

            if ($day->slots->isEmpty()) {
                $errors[$prefix.'.slots'] = 'Each configured day needs at least one fixed slot.';

                continue;
            }

            $previousEnd = null;

            foreach ($day->slots->sortBy('start_time') as $slot) {
                $slotStart = substr((string) $slot->start_time, 0, 5);
                $slotEnd = substr((string) $slot->end_time, 0, 5);

                if ($slotStart >= $slotEnd) {
                    $errors[$prefix.'.slots'] = 'Every slot must end after it starts.';
                }

                if ($slotStart < $start || $slotEnd > $end) {
                    $errors[$prefix.'.slots'] = 'Every slot must fall inside the operating hours.';
                }

                if ($previousEnd !== null && $slotStart < $previousEnd) {
                    $errors[$prefix.'.slots'] = 'Slots must not overlap.';
                }

                $previousEnd = $previousEnd === null ? $slotEnd : max($previousEnd, $slotEnd);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateMeetingPlanForApproval(SchedulingMeetingPlan $plan): void
    {
        $plan->loadMissing('requirements');
        $errors = [];

        if ($plan->requirements->isEmpty()) {
            $errors['requirements'] = 'At least one meeting requirement is required.';
        }

        if (! $plan->requirements->contains(fn (SchedulingMeetingRequirement $requirement): bool => $requirement->mandatory)) {
            $errors['requirements'] = 'At least one meeting requirement must be mandatory.';
        }

        foreach ($plan->requirements as $index => $requirement) {
            $prefix = 'requirements.'.$index;

            if (! in_array($requirement->meeting_type, self::MEETING_TYPES, true)) {
                $errors[$prefix.'.meeting_type'] = 'Unsupported meeting type.';
            }

            if (! in_array($requirement->modality, self::MODALITIES, true)) {
                $errors[$prefix.'.modality'] = 'Unsupported modality.';
            }

            if ($requirement->required_room_type !== null
                && ! in_array($requirement->required_room_type, self::ROOM_TYPES, true)) {
                $errors[$prefix.'.required_room_type'] = 'Unsupported room type.';
            }

            if ($requirement->modality === 'online' && $requirement->required_room_type !== null) {
                $errors[$prefix.'.required_room_type'] = 'Online requirements cannot require a physical room type.';
            }

            if ($requirement->frequency_per_week < 1 || $requirement->frequency_per_week > 14) {
                $errors[$prefix.'.frequency_per_week'] = 'Frequency must be between 1 and 14 meetings per week.';
            }

            if ($requirement->duration_minutes < 1 || $requirement->duration_minutes > 600) {
                $errors[$prefix.'.duration_minutes'] = 'Duration must be between 1 and 600 minutes.';
            }

            if ($requirement->allowed_days !== null) {
                $days = $requirement->allowed_days;

                if ($days === [] || $days !== array_values(array_unique($days)) || $days !== $this->sortedInts($days)) {
                    $errors[$prefix.'.allowed_days'] = 'Allowed days must be a nonempty sorted unique list.';
                }

                if (collect($days)->contains(fn ($day): bool => ! is_int($day) || $day < 1 || $day > 5)) {
                    $errors[$prefix.'.allowed_days'] = 'Only Monday through Friday are supported.';
                }
            }

            if ($requirement->valid_start_times !== null) {
                $starts = $requirement->valid_start_times;

                if ($starts === [] || $starts !== array_values(array_unique($starts)) || $starts !== $this->sortedStrings($starts)) {
                    $errors[$prefix.'.valid_start_times'] = 'Valid start times must be a nonempty sorted unique list.';
                }

                if (collect($starts)->contains(fn ($time): bool => ! is_string($time) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1)) {
                    $errors[$prefix.'.valid_start_times'] = 'Valid start times must use HH:MM format.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOffering(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'section_id' => $offering->section_id,
            'subject_id' => $offering->subject_id,
            'expected_students' => $offering->expected_students,
            'subject' => $offering->subject === null ? null : [
                'id' => $offering->subject->id,
                'code' => $offering->subject->code,
                'title' => $offering->subject->title,
                'lecture_hours' => $offering->subject->lecture_hours,
                'lab_hours' => $offering->subject->lab_hours,
            ],
            'section' => $offering->section === null ? null : [
                'id' => $offering->section->id,
                'code' => $offering->section->code,
                'name' => $offering->section->name,
            ],
        ];
    }

    /**
     * @return list<int>|null
     */
    private function sortedNullableIntList(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return $this->sortedInts(array_map(fn ($item): int => (int) $item, $value));
    }

    /**
     * @return list<string>|null
     */
    private function sortedNullableStringList(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return $this->sortedStrings(array_map(fn ($item): string => (string) $item, $value));
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function sortedInts(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
