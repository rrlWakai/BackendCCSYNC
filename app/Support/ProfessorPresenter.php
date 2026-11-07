<?php

namespace App\Support;

use App\Models\FacultyAssignment;
use App\Models\FacultyAvailability;
use App\Models\Professor;

final class ProfessorPresenter
{
    public static function toDirectoryArray(Professor $professor): array
    {
        $professor->loadMissing(['user:id,name,email,status,created_at', 'program:id,code,name,status']);

        return [
            'id' => $professor->id,
            'user_id' => $professor->user_id,
            'name' => $professor->user->name,
            'email' => $professor->user->email,
            'account_status' => $professor->user->status,
            'employee_number' => $professor->employee_number,
            'employment_type' => $professor->employment_type,
            'specialization' => $professor->specialization,
            'profile_status' => $professor->status,
            'created_at' => $professor->created_at?->toIso8601String(),
            'program' => self::programArray($professor),
        ];
    }

    public static function toDetailArray(Professor $professor): array
    {
        $professor->loadMissing([
            'user:id,name,email,status,created_at',
            'program:id,code,name,status',
            'facultyAvailability.academicTerm:id,academic_year,term,name',
            'facultyAssignments.subjectOffering.subject:id,code,title,units',
            'facultyAssignments.subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
            'facultyAssignments.subjectOffering.section.program:id,code,name',
            'facultyAssignments.subjectOffering.section.yearLevel:id,level,name',
            'facultyAssignments.subjectOffering.academicTerm:id,academic_year,term,name',
        ]);

        return [
            ...self::toDirectoryArray($professor),
            'user_created_at' => $professor->user->created_at?->toIso8601String(),
            'availability' => $professor->facultyAvailability
                ->sortBy(fn (FacultyAvailability $availability): string => sprintf(
                    '%05d-%d-%s',
                    $availability->academic_term_id,
                    $availability->day_of_week,
                    $availability->start_time,
                ))
                ->map(self::availabilityArray(...))
                ->values()
                ->all(),
            'assignments' => $professor->facultyAssignments
                ->sortBy('id')
                ->map(self::assignmentArray(...))
                ->values()
                ->all(),
        ];
    }

    public static function availabilityArray(FacultyAvailability $availability): array
    {
        $availability->loadMissing('academicTerm:id,academic_year,term,name');

        return [
            'id' => $availability->id,
            'academic_term_id' => $availability->academic_term_id,
            'academic_term' => [
                'id' => $availability->academicTerm->id,
                'academic_year' => $availability->academicTerm->academic_year,
                'term' => $availability->academicTerm->term,
                'name' => $availability->academicTerm->name,
            ],
            'day_of_week' => $availability->day_of_week,
            'start_time' => substr((string) $availability->start_time, 0, 5),
            'end_time' => substr((string) $availability->end_time, 0, 5),
            'availability_type' => $availability->availability_type,
            'notes' => $availability->notes,
        ];
    }

    public static function assignmentArray(FacultyAssignment $assignment): array
    {
        $offering = $assignment->subjectOffering;
        $section = $offering->section;

        return [
            'id' => $assignment->id,
            'assignment_role' => $assignment->assignment_role,
            'assigned_units' => $assignment->assigned_units,
            'status' => $assignment->status,
            'offering' => [
                'id' => $offering->id,
                'status' => $offering->status,
                'subject' => [
                    'id' => $offering->subject->id,
                    'code' => $offering->subject->code,
                    'title' => $offering->subject->title,
                    'units' => $offering->subject->units,
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
                    'id' => $offering->academicTerm->id,
                    'academic_year' => $offering->academicTerm->academic_year,
                    'term' => $offering->academicTerm->term,
                    'name' => $offering->academicTerm->name,
                ],
            ],
        ];
    }

    private static function programArray(Professor $professor): ?array
    {
        if ($professor->program === null) {
            return null;
        }

        return [
            'id' => $professor->program->id,
            'code' => $professor->program->code,
            'name' => $professor->program->name,
            'status' => $professor->program->status,
        ];
    }
}
