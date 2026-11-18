<?php

namespace App\Support;

use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentMajorSelection;
use App\Models\SubjectOffering;

final class StudentPresenter
{
    public static function toDirectoryArray(Student $student): array
    {
        $student->loadMissing([
            'user:id,name,email,status,created_at',
            'program:id,code,name',
            'currentPlacement.academicYear:id,label,status',
            'currentPlacement.yearLevel:id,level,name',
            'currentPlacement.major:id,program_id,code,name',
            'currentPlacement.section:id,code,name',
        ]);

        return [
            'id' => $student->id,
            'user_id' => $student->user_id,
            'student_number' => $student->student_number,
            'name' => $student->user->name,
            'email' => $student->user->email,
            'account_status' => $student->user->status,
            'profile_status' => $student->status,
            'admitted_on' => $student->admitted_on?->toDateString(),
            'created_at' => $student->created_at?->toIso8601String(),
            'program' => self::programArray($student),
            'current_placement' => self::placementArray($student->currentPlacement),
        ];
    }

    public static function toDetailArray(Student $student, bool $includeEmail): array
    {
        $student->loadMissing([
            'user:id,name,email,status,created_at',
            'program:id,code,name',
            'currentPlacement.academicYear:id,label,status',
            'currentPlacement.yearLevel:id,level,name',
            'currentPlacement.major:id,program_id,code,name',
            'currentPlacement.section:id,code,name',
        ]);

        $result = [
            'id' => $student->id,
            'user_id' => $student->user_id,
            'student_number' => $student->student_number,
            'name' => $student->user->name,
            'account_status' => $student->user->status,
            'profile_status' => $student->status,
            'admitted_on' => $student->admitted_on?->toDateString(),
            'created_at' => $student->created_at?->toIso8601String(),
            'program' => self::programArray($student),
            'current_placement' => self::placementArray($student->currentPlacement),
            'placements' => $student->placements
                ->map(fn (StudentAcademicPlacement $placement): array => self::placementArray($placement))
                ->all(),
            'major_selections' => $student->majorSelections
                ->map(fn (StudentMajorSelection $selection): array => self::majorSelectionArray($selection))
                ->all(),
            'enrollments' => $student->enrollments
                ->map(fn ($enrollment): array => [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                    'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                    'dropped_at' => $enrollment->dropped_at?->toIso8601String(),
                    'subject_offering' => self::offeringArray($enrollment->subjectOffering),
                ])
                ->all(),
        ];

        if ($includeEmail) {
            $result['email'] = $student->user->email;
        }

        return $result;
    }

    private static function programArray(Student $student): ?array
    {
        if ($student->program === null) {
            return null;
        }

        return [
            'id' => $student->program->id,
            'code' => $student->program->code,
            'name' => $student->program->name,
            'status' => $student->program->status,
        ];
    }

    private static function placementArray(?StudentAcademicPlacement $placement): ?array
    {
        if ($placement === null) {
            return null;
        }

        return [
            'id' => $placement->id,
            'status' => $placement->status,
            'is_current' => $placement->is_current,
            'effective_on' => $placement->effective_on?->toDateString(),
            'ends_on' => $placement->ends_on?->toDateString(),
            'academic_year' => $placement->academicYear === null ? null : [
                'id' => $placement->academicYear->id,
                'label' => $placement->academicYear->label,
            ],
            'academic_term' => $placement->academicTerm === null ? null : [
                'id' => $placement->academicTerm->id,
                'academic_year' => $placement->academicTerm->academic_year,
                'term' => $placement->academicTerm->term,
                'name' => $placement->academicTerm->name,
            ],
            'year_level' => $placement->yearLevel === null ? null : [
                'id' => $placement->yearLevel->id,
                'level' => $placement->yearLevel->level,
                'name' => $placement->yearLevel->name,
            ],
            'major' => $placement->major === null ? null : [
                'id' => $placement->major->id,
                'code' => $placement->major->code,
                'name' => $placement->major->name,
            ],
            'section' => $placement->section === null ? null : [
                'id' => $placement->section->id,
                'code' => $placement->section->code,
                'name' => $placement->section->name,
            ],
        ];
    }

    private static function majorSelectionArray(StudentMajorSelection $selection): array
    {
        return [
            'id' => $selection->id,
            'status' => $selection->status,
            'selected_at' => $selection->selected_at?->toIso8601String(),
            'reviewed_at' => $selection->reviewed_at?->toIso8601String(),
            'remarks' => $selection->remarks,
            'major' => $selection->major === null ? null : [
                'id' => $selection->major->id,
                'code' => $selection->major->code,
                'name' => $selection->major->name,
            ],
            'academic_year' => $selection->academicYear === null ? null : [
                'id' => $selection->academicYear->id,
                'label' => $selection->academicYear->label,
            ],
        ];
    }

    private static function offeringArray(SubjectOffering $offering): array    {
        $section = $offering->section;

        return [
            'id' => $offering->id,
            'status' => $offering->status,
            'expected_students' => $offering->expected_students,
            'subject' => [
                'id' => $offering->subject->id,
                'code' => $offering->subject->code,
                'title' => $offering->subject->title,
                'units' => $offering->subject->units,
            ],
            'academic_term' => [
                'id' => $offering->academicTerm->id,
                'academic_year' => $offering->academicTerm->academic_year,
                'term' => $offering->academicTerm->term,
                'name' => $offering->academicTerm->name,
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
        ];
    }
}
