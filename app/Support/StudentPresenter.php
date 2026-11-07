<?php

namespace App\Support;

use App\Models\Student;
use App\Models\SubjectOffering;

final class StudentPresenter
{
    public static function toDirectoryArray(Student $student): array
    {
        $student->loadMissing(['user:id,name,email,status,created_at', 'program:id,code,name']);

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
        ];
    }

    public static function toDetailArray(Student $student, bool $includeEmail): array
    {
        $student->loadMissing([
            'user:id,name,email,status,created_at',
            'program:id,code,name',
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

    private static function offeringArray(SubjectOffering $offering): array
    {
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
