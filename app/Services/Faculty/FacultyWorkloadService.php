<?php

namespace App\Services\Faculty;

use App\Models\AcademicTerm;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\ScheduleAssignment;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Builder;

final class FacultyWorkloadService
{
    public function summariesFor(iterable $professors): array
    {
        $professorList = collect($professors)
            ->filter(fn (mixed $professor): bool => $professor instanceof Professor)
            ->values();

        if ($professorList->isEmpty()) {
            return [];
        }

        $termResolution = $this->resolveTerm();
        $term = $termResolution['term'];

        $summaries = [];
        foreach ($professorList as $professor) {
            $summaries[(int) $professor->id] = $this->emptySummary($professor, $termResolution);
        }

        if ($term === null) {
            return $summaries;
        }

        $professorIds = $professorList
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $assignments = FacultyAssignment::query()
            ->whereIn('professor_id', $professorIds)
            ->where('status', 'active')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->where('academic_term_id', $term->id)
                ->where('status', '<>', 'cancelled'))
            ->with([
                'subjectOffering:id,academic_term_id,section_id,subject_id,status',
                'subjectOffering.subject:id,code,title,units,lecture_hours,lab_hours',
                'subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
                'subjectOffering.section.program:id,code,name',
                'subjectOffering.section.yearLevel:id,level,name',
            ])
            ->orderBy('id')
            ->get();

        $offeringContexts = [];
        foreach ($assignments as $assignment) {
            $offering = $assignment->subjectOffering;

            if ($offering === null || $offering->subject === null || $offering->section === null) {
                continue;
            }

            $offeringContexts[(int) $offering->id] = $this->offeringContext($offering);
        }

        $offeringIds = $assignments
            ->pluck('subject_offering_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $conflictIssues = [];
        foreach ($this->assignmentConflicts($offeringIds) as $offeringId => $professors) {
            $conflictIssues[$offeringId] = [
                'code' => 'assignment_conflict',
                'message' => 'Multiple distinct professors are actively assigned to this class assignment. Workload cannot be allocated until the assignment conflict is resolved.',
                'offering' => $offeringContexts[$offeringId] ?? null,
                'professors' => $professors,
            ];
        }

        $offeringBuckets = [];
        $assignmentContext = [];
        $issueKeys = [];

        foreach ($assignments as $assignment) {
            $offering = $assignment->subjectOffering;

            if ($offering === null || $offering->subject === null || $offering->section === null) {
                continue;
            }

            $professorId = (int) $assignment->professor_id;
            $offeringId = (int) $offering->id;
            $issueKey = $professorId.'|'.$offeringId;

            if (isset($conflictIssues[$offeringId])) {
                if (! isset($issueKeys[$issueKey])) {
                    $summaries[$professorId]['issues'][] = $conflictIssues[$offeringId];
                    $issueKeys[$issueKey] = true;
                }

                continue;
            }

            $hours = $this->subjectHours($offering->subject);

            if (! $hours['valid']) {
                if (! isset($issueKeys[$issueKey])) {
                    $summaries[$professorId]['issues'][] = [
                        'code' => 'incomplete_subject_hours',
                        'message' => $hours['message'],
                        'offering' => $offeringContexts[$offeringId] ?? $this->offeringContext($offering),
                    ];
                    $issueKeys[$issueKey] = true;
                }

                continue;
            }

            $key = $this->offeringKey($professorId, $offeringId);
            $assignmentContext[(int) $assignment->id] = [
                'professor_id' => $professorId,
                'offering_id' => $offeringId,
            ];

            if (! isset($offeringBuckets[$key])) {
                $offeringBuckets[$key] = [
                    'professor_id' => $professorId,
                    'offering_id' => $offeringId,
                    'offering_status' => $offering->status,
                    'subject' => [
                        'id' => (int) $offering->subject->id,
                        'code' => $offering->subject->code,
                        'title' => $offering->subject->title,
                        'units' => $offering->subject->units,
                        'lecture_hours' => $offering->subject->lecture_hours,
                        'lab_hours' => $offering->subject->lab_hours,
                    ],
                    'section' => [
                        'id' => (int) $offering->section->id,
                        'code' => $offering->section->code,
                        'name' => $offering->section->name,
                        'program' => [
                            'id' => (int) $offering->section->program->id,
                            'code' => $offering->section->program->code,
                            'name' => $offering->section->program->name,
                        ],
                        'year_level' => [
                            'id' => (int) $offering->section->yearLevel->id,
                            'level' => (int) $offering->section->yearLevel->level,
                            'name' => $offering->section->yearLevel->name,
                        ],
                    ],
                    'academic_term' => $this->termArray($term),
                    'required_weekly_hours' => $hours['hours'],
                    'scheduled_weekly_hours' => 0.0,
                    'has_scheduled_meetings' => false,
                    'assignment_roles' => [],
                    'schedule_ids' => [],
                ];
            }

            if (! in_array($assignment->assignment_role, $offeringBuckets[$key]['assignment_roles'], true)) {
                $offeringBuckets[$key]['assignment_roles'][] = $assignment->assignment_role;
            }
        }

        if ($assignmentContext !== []) {
            $scheduleAssignments = ScheduleAssignment::query()
                ->where('status', 'active')
                ->whereIn('faculty_assignment_id', array_keys($assignmentContext))
                ->whereHas('classSchedule', fn (Builder $query) => $query
                    ->where('status', 'published'))
                ->with('classSchedule:id,subject_offering_id,start_time,end_time,status')
                ->orderBy('id')
                ->get();

            foreach ($scheduleAssignments as $scheduleAssignment) {
                $context = $assignmentContext[(int) $scheduleAssignment->faculty_assignment_id] ?? null;
                $schedule = $scheduleAssignment->classSchedule;

                if ($context === null || $schedule === null
                    || (int) $schedule->subject_offering_id !== $context['offering_id']) {
                    continue;
                }

                $key = $this->offeringKey($context['professor_id'], $context['offering_id']);

                if (! isset($offeringBuckets[$key])) {
                    continue;
                }

                $scheduleId = (int) $schedule->id;

                if (isset($offeringBuckets[$key]['schedule_ids'][$scheduleId])) {
                    continue;
                }

                $offeringBuckets[$key]['schedule_ids'][$scheduleId] = true;
                $offeringBuckets[$key]['has_scheduled_meetings'] = true;
                $offeringBuckets[$key]['scheduled_weekly_hours'] += $this->durationHours(
                    $schedule->start_time,
                    $schedule->end_time,
                );
            }
        }

        $offeringsByProfessor = [];

        foreach ($offeringBuckets as $offering) {
            unset($offering['schedule_ids']);

            $requiredHours = round((float) $offering['required_weekly_hours'], 1);
            $scheduledHours = round((float) $offering['scheduled_weekly_hours'], 1);

            $offering['required_weekly_hours'] = $requiredHours;
            $offering['scheduled_weekly_hours'] = $scheduledHours;
            $offering['schedule_discrepancy_hours'] = $offering['has_scheduled_meetings']
                ? round($scheduledHours - $requiredHours, 1)
                : null;

            $offeringsByProfessor[$offering['professor_id']][] = $offering;
        }

        foreach ($summaries as $professorId => $summary) {
            $offerings = $offeringsByProfessor[$professorId] ?? [];

            usort($offerings, fn (array $left, array $right): int => [
                $left['subject']['code'],
                $left['section']['code'],
            ] <=> [
                $right['subject']['code'],
                $right['section']['code'],
            ]);

            $assignedHours = 0.0;
            $scheduledHours = 0.0;
            $unscheduledOfferings = 0;
            $discrepancies = [];

            foreach ($offerings as $offering) {
                $assignedHours += (float) $offering['required_weekly_hours'];
                $scheduledHours += (float) $offering['scheduled_weekly_hours'];

                if (! $offering['has_scheduled_meetings']) {
                    $unscheduledOfferings++;
                }

                if ($offering['schedule_discrepancy_hours'] !== null
                    && abs((float) $offering['schedule_discrepancy_hours']) >= 0.05) {
                    $discrepancies[] = [
                        'offering_id' => $offering['offering_id'],
                        'subject_code' => $offering['subject']['code'],
                        'subject_title' => $offering['subject']['title'],
                        'section_code' => $offering['section']['code'],
                        'required_weekly_hours' => $offering['required_weekly_hours'],
                        'scheduled_weekly_hours' => $offering['scheduled_weekly_hours'],
                        'difference_hours' => $offering['schedule_discrepancy_hours'],
                    ];
                }
            }

            $summaries[(int) $professorId] = $this->applyTotals(
                $summary,
                round($assignedHours, 1),
                round($scheduledHours, 1),
                $offerings,
                $discrepancies,
                $unscheduledOfferings,
            );
        }

        return $summaries;
    }

    private function resolveTerm(): array
    {
        $terms = AcademicTerm::query()
            ->where('is_current', true)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        if ($terms->isEmpty()) {
            return [
                'term' => null,
                'status' => 'missing',
                'message' => 'No current academic term is configured. Faculty workload is unavailable until exactly one term is marked current.',
                'current_terms' => [],
            ];
        }

        if ($terms->count() > 1) {
            return [
                'term' => null,
                'status' => 'multiple',
                'message' => 'Multiple current academic terms are configured. Faculty workload is unavailable until exactly one current term remains.',
                'current_terms' => $terms
                    ->map(fn (AcademicTerm $term): array => $this->termArray($term))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'term' => $terms->first(),
            'status' => 'resolved',
            'message' => null,
            'current_terms' => [$this->termArray($terms->first())],
        ];
    }

    private function assignmentConflicts(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $assignments = FacultyAssignment::query()
            ->whereIn('subject_offering_id', $offeringIds)
            ->where('status', 'active')
            ->with([
                'professor:id,user_id',
                'professor.user:id,name',
            ])
            ->orderBy('id')
            ->get()
            ->groupBy('subject_offering_id');

        $conflicts = [];

        foreach ($assignments as $offeringId => $rows) {
            $professorIds = $rows
                ->pluck('professor_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($professorIds->count() <= 1) {
                continue;
            }

            $professors = $professorIds
                ->map(function (int $professorId) use ($rows): array {
                    $professor = $rows
                        ->firstWhere('professor_id', $professorId)
                        ?->professor;

                    return [
                        'id' => $professorId,
                        'name' => $professor?->user?->name ?? 'Professor #'.$professorId,
                    ];
                })
                ->values()
                ->all();

            $conflicts[(int) $offeringId] = $professors;
        }

        return $conflicts;
    }

    private function emptySummary(Professor $professor, array $termResolution): array
    {
        $quota = $professor->weekly_teaching_hours_quota;
        $weeklyQuota = $quota === null ? null : round((float) $quota, 1);
        $term = $termResolution['term'];
        $termUnavailable = $term === null;
        $status = match ($termResolution['status']) {
            'missing' => 'no_current_term',
            'multiple' => 'multiple_current_terms',
            default => $this->status($weeklyQuota, 0.0),
        };
        $issues = [];

        if ($termResolution['status'] === 'missing') {
            $issues[] = [
                'code' => 'no_current_term',
                'message' => $termResolution['message'],
            ];
        } elseif ($termResolution['status'] === 'multiple') {
            $issues[] = [
                'code' => 'multiple_current_terms',
                'message' => $termResolution['message'],
            ];
        }

        return [
            'academic_term' => $term === null ? null : $this->termArray($term),
            'term_available' => ! $termUnavailable,
            'term_resolution' => [
                'status' => $termResolution['status'],
                'message' => $termResolution['message'],
                'current_terms' => $termResolution['current_terms'],
            ],
            'weekly_quota' => $weeklyQuota,
            'assigned_hours' => 0.0,
            'scheduled_hours' => 0.0,
            'remaining_hours' => $termUnavailable || $weeklyQuota === null ? null : $weeklyQuota,
            'excess_hours' => $termUnavailable ? null : 0.0,
            'utilization_percentage' => $termUnavailable || $weeklyQuota === null ? null : 0.0,
            'status' => $status,
            'calculation_complete' => ! $termUnavailable,
            'issues_count' => count($issues),
            'issues' => $issues,
            'offerings' => [],
            'discrepancies' => [],
            'has_discrepancies' => false,
            'unscheduled_offerings_count' => 0,
        ];
    }

    private function applyTotals(
        array $summary,
        float $assignedHours,
        float $scheduledHours,
        array $offerings,
        array $discrepancies,
        int $unscheduledOfferings,
    ): array {
        $quota = $summary['weekly_quota'];
        $issues = array_values($summary['issues']);
        $hasIssues = $issues !== [];

        return [
            ...$summary,
            'assigned_hours' => $assignedHours,
            'scheduled_hours' => $scheduledHours,
            'remaining_hours' => $hasIssues || $quota === null ? null : round($quota - $assignedHours, 1),
            'excess_hours' => $hasIssues ? null : ($quota === null ? 0.0 : max(0.0, round($assignedHours - $quota, 1))),
            'utilization_percentage' => $hasIssues ? null : $this->utilization($quota, $assignedHours),
            'status' => $hasIssues ? 'incomplete' : $this->status($quota, $assignedHours),
            'calculation_complete' => ! $hasIssues,
            'issues_count' => count($issues),
            'issues' => $issues,
            'offerings' => array_values($offerings),
            'discrepancies' => array_values($discrepancies),
            'has_discrepancies' => $discrepancies !== [],
            'unscheduled_offerings_count' => $unscheduledOfferings,
        ];
    }

    private function status(?float $quota, float $assignedHours): string
    {
        if ($quota === null) {
            return 'not_configured';
        }

        if ($assignedHours > $quota + 0.0001) {
            return 'exceeds_quota';
        }

        if (abs($assignedHours - $quota) <= 0.0001) {
            return 'at_quota';
        }

        return 'within_quota';
    }

    private function utilization(?float $quota, float $assignedHours): ?float
    {
        if ($quota === null) {
            return null;
        }

        if ($quota <= 0) {
            return $assignedHours > 0 ? null : 0.0;
        }

        return round(($assignedHours / $quota) * 100, 1);
    }

    private function subjectHours(Subject $subject): array
    {
        $lecture = $subject->lecture_hours;
        $lab = $subject->lab_hours;

        if ($lecture === null || $lab === null) {
            return [
                'valid' => false,
                'hours' => null,
                'message' => 'The subject lecture and laboratory weekly hours are incomplete. Both components must be explicitly configured.',
            ];
        }

        $lectureHours = round((float) $lecture, 1);
        $labHours = round((float) $lab, 1);

        if ($lectureHours < 0 || $labHours < 0) {
            return [
                'valid' => false,
                'hours' => null,
                'message' => 'The subject weekly hour requirements are invalid because a component is negative.',
            ];
        }

        if ($lectureHours <= 0 && $labHours <= 0) {
            return [
                'valid' => false,
                'hours' => null,
                'message' => 'The subject has no lecture or laboratory weekly hours. At least one component must be greater than zero.',
            ];
        }

        return [
            'valid' => true,
            'hours' => round($lectureHours + $labHours, 1),
            'message' => null,
        ];
    }

    private function offeringContext(SubjectOffering $offering): array
    {
        return [
            'id' => (int) $offering->id,
            'status' => $offering->status,
            'subject' => [
                'id' => (int) $offering->subject->id,
                'code' => $offering->subject->code,
                'title' => $offering->subject->title,
                'lecture_hours' => $offering->subject->lecture_hours,
                'lab_hours' => $offering->subject->lab_hours,
            ],
            'section' => [
                'id' => (int) $offering->section->id,
                'code' => $offering->section->code,
                'name' => $offering->section->name,
            ],
        ];
    }

    private function durationHours(string $startTime, string $endTime): float
    {
        return max(0.0, ($this->timeToSeconds($endTime) - $this->timeToSeconds($startTime)) / 3600);
    }

    private function timeToSeconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_pad(explode(':', $time), 3, '0');

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;
    }

    private function offeringKey(int $professorId, int $offeringId): string
    {
        return $professorId.'|'.$offeringId;
    }

    private function termArray(AcademicTerm $term): array
    {
        return [
            'id' => (int) $term->id,
            'academic_year' => $term->academic_year,
            'term' => $term->term,
            'name' => $term->name,
            'status' => $term->status,
            'is_current' => (bool) $term->is_current,
        ];
    }
}
