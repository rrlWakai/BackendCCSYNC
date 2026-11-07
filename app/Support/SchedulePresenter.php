<?php

namespace App\Support;

use App\Models\ClassSchedule;
use App\Models\FacultyAssignment;
use App\Models\RoomAllocation;

final class SchedulePresenter
{
    public static function toArray(ClassSchedule $schedule, bool $includeHistory = true): array
    {
        $offering = $schedule->subjectOffering;
        $section = $offering->section;
        $term = $offering->academicTerm;

        return [
            'id' => $schedule->id,
            'subject_offering_id' => $schedule->subject_offering_id,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'schedule_type' => $schedule->schedule_type,
            'modality' => $schedule->modality,
            'status' => $schedule->status,
            'offering' => [
                'id' => $offering->id,
                'expected_students' => $offering->expected_students,
                'subject' => [
                    'id' => $offering->subject->id,
                    'code' => $offering->subject->code,
                    'title' => $offering->subject->title,
                ],
                'section' => [
                    'id' => $section->id,
                    'code' => $section->code,
                    'name' => $section->name,
                    'program' => [
                        'id' => $section->program->id,
                        'code' => $section->program->code,
                        'name' => $section->program->name,
                    ],
                    'year_level' => [
                        'id' => $section->yearLevel->id,
                        'level' => $section->yearLevel->level,
                        'name' => $section->yearLevel->name,
                    ],
                ],
                'academic_term' => [
                    'id' => $term->id,
                    'academic_year' => $term->academic_year,
                    'term' => $term->term,
                    'name' => $term->name,
                    'starts_on' => $term->starts_on?->toDateString(),
                    'ends_on' => $term->ends_on?->toDateString(),
                ],
            ],
            'assignments' => $schedule->scheduleAssignments
                ->when(! $includeHistory, fn ($assignments) => $assignments->where('status', 'active'))
                ->map(fn ($assignment): array => [
                    'id' => $assignment->id,
                    'faculty_assignment_id' => $assignment->faculty_assignment_id,
                    'is_primary' => $assignment->is_primary,
                    'status' => $assignment->status,
                    'assignment_role' => $assignment->facultyAssignment->assignment_role,
                    'professor' => [
                        'id' => $assignment->facultyAssignment->professor->id,
                        'name' => $assignment->facultyAssignment->professor->user->name,
                    ],
                ])->values()->all(),
            'room_allocations' => $schedule->roomAllocations
                ->when(! $includeHistory, fn ($allocations) => $allocations->where('status', 'active'))
                ->map(fn (RoomAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'room_id' => $allocation->room_id,
                    'status' => $allocation->status,
                    'room' => [
                        'id' => $allocation->room->id,
                        'code' => $allocation->room->code,
                        'name' => $allocation->room->name,
                        'building' => $allocation->room->building,
                        'capacity' => $allocation->room->capacity,
                    ],
                ])->values()->all(),
        ];
    }

    public static function facultyAssignmentArray(FacultyAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'professor_id' => $assignment->professor_id,
            'subject_offering_id' => $assignment->subject_offering_id,
            'assignment_role' => $assignment->assignment_role,
            'assigned_units' => $assignment->assigned_units,
            'status' => $assignment->status,
            'professor' => [
                'id' => $assignment->professor->id,
                'name' => $assignment->professor->user->name,
                'employee_number' => $assignment->professor->employee_number,
            ],
        ];
    }
}
