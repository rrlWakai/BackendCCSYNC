<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Major;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\YearLevel;
use Database\Seeders\Concerns\RefuseProtectedDatabase;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    use RefuseProtectedDatabase;

    public function run(): void
    {
        $this->refuseProtectedDatabase();

        $academicYear = AcademicYear::updateOrCreate(
            ['label' => '2026-2027'],
            [
                'starts_on' => '2026-08-03',
                'ends_on' => '2027-05-21',
                'status' => 'active',
            ],
        );

        $bsit = Program::updateOrCreate(
            ['code' => 'BSIT'],
            ['name' => 'BS Information Technology', 'status' => 'active'],
        );

        $bscs = Program::updateOrCreate(
            ['code' => 'BSCS'],
            ['name' => 'BS Computer Science', 'status' => 'active'],
        );

        $yearLevels = [
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            4 => 'Fourth Year',
            5 => 'Fifth Year',
        ];

        foreach ($yearLevels as $level => $name) {
            YearLevel::updateOrCreate(['level' => $level], ['name' => $name]);
        }

        // Official CCS specializations. Curriculum contents remain data-managed
        // through curriculum mappings and are intentionally not fabricated here.
        foreach ([
            [$bsit->id, 'WMAD', 'Web and Mobile Application Development'],
            [$bsit->id, 'SMP', 'Service Management Program'],
            [$bsit->id, 'NA', 'Network Administration'],
            [$bscs->id, 'AMG', 'Animation and Motion Graphics'],
            [$bscs->id, 'IS', 'Information Systems'],
        ] as [$programId, $code, $name]) {
            Major::updateOrCreate(
                ['program_id' => $programId, 'code' => $code],
                ['name' => $name, 'status' => 'active'],
            );
        }

        $currentTerm = AcademicTerm::updateOrCreate(
            ['academic_year' => '2026-2027', 'term' => 'first'],
            [
                'academic_year_id' => $academicYear->id,
                'name' => 'First Semester 2026-2027',
                'starts_on' => '2026-08-03',
                'ends_on' => '2026-12-18',
                'is_current' => true,
                'status' => 'active',
            ],
        );

        AcademicTerm::whereKeyNot($currentTerm->id)->update(['is_current' => false]);

        $futureTerm = AcademicTerm::updateOrCreate(
            ['academic_year' => '2026-2027', 'term' => 'second'],
            [
                'academic_year_id' => $academicYear->id,
                'name' => 'Second Semester 2026-2027',
                'starts_on' => '2027-01-11',
                'ends_on' => '2027-05-21',
                'is_current' => false,
                'status' => 'planned',
            ],
        );

        $firstYear = YearLevel::where('level', 1)->firstOrFail();
        $secondYear = YearLevel::where('level', 2)->firstOrFail();
        $thirdYear = YearLevel::where('level', 3)->firstOrFail();
        $wmad = Major::where('program_id', $bsit->id)->where('code', 'WMAD')->firstOrFail();
        $smp = Major::where('program_id', $bsit->id)->where('code', 'SMP')->firstOrFail();
        $amg = Major::where('program_id', $bscs->id)->where('code', 'AMG')->firstOrFail();
        $is = Major::where('program_id', $bscs->id)->where('code', 'IS')->firstOrFail();

        $bsitSection = Section::updateOrCreate(
            ['academic_term_id' => $currentTerm->id, 'code' => 'BSIT-1A'],
            [
                'program_id' => $bsit->id,
                'year_level_id' => $firstYear->id,
                'name' => 'BSIT First Year A',
                'capacity' => 45,
                'status' => 'active',
            ],
        );

        Section::updateOrCreate(
            ['academic_term_id' => $currentTerm->id, 'code' => 'BSIT-2A'],
            [
                'program_id' => $bsit->id,
                'year_level_id' => $secondYear->id,
                'name' => 'BSIT Second Year A',
                'capacity' => 40,
                'status' => 'active',
            ],
        );

        Section::updateOrCreate(
            ['academic_term_id' => $currentTerm->id, 'code' => 'BSCS-1A'],
            [
                'program_id' => $bscs->id,
                'year_level_id' => $firstYear->id,
                'name' => 'BSCS First Year A',
                'capacity' => 40,
                'status' => 'active',
            ],
        );

        Section::updateOrCreate(
            ['academic_term_id' => $futureTerm->id, 'code' => 'BSIT-1A'],
            [
                'program_id' => $bsit->id,
                'year_level_id' => $firstYear->id,
                'name' => 'BSIT First Year A',
                'capacity' => 45,
                'status' => 'active',
            ],
        );

        foreach ([
            ['BSIT-3A-WMAD', $bsit, $thirdYear, $wmad, 'BSIT Third Year WMAD A'],
            ['BSIT-3A-SMP', $bsit, $thirdYear, $smp, 'BSIT Third Year SMP A'],
            ['BSCS-3A-AMG', $bscs, $thirdYear, $amg, 'BSCS Third Year AMG A'],
            ['BSCS-3A-IS', $bscs, $thirdYear, $is, 'BSCS Third Year IS A'],
        ] as [$code, $program, $yearLevel, $major, $name]) {
            Section::updateOrCreate(
                ['academic_term_id' => $currentTerm->id, 'code' => $code],
                [
                    'program_id' => $program->id,
                    'year_level_id' => $yearLevel->id,
                    'major_id' => $major->id,
                    'name' => $name,
                    'capacity' => 40,
                    'status' => 'active',
                ],
            );
        }

        $subjects = [
            ['code' => 'IT101', 'title' => 'Introduction to Information Technology', 'units' => 3.0, 'lecture_hours' => 2.0, 'lab_hours' => 3.0],
            ['code' => 'IT102', 'title' => 'Computer Programming 1', 'units' => 3.0, 'lecture_hours' => 2.0, 'lab_hours' => 3.0],
            ['code' => 'CS101', 'title' => 'Computer Science Fundamentals', 'units' => 3.0, 'lecture_hours' => 3.0, 'lab_hours' => 0.0],
            ['code' => 'GE101', 'title' => 'Purposive Communication', 'units' => 3.0, 'lecture_hours' => 3.0, 'lab_hours' => 0.0],
        ];

        foreach ($subjects as $subject) {
            Subject::updateOrCreate(
                ['code' => $subject['code']],
                $subject + ['status' => 'active'],
            );
        }

        $rooms = [
            ['code' => 'LEC-201', 'name' => 'Lecture Room 201', 'building' => 'Main', 'floor' => '2', 'capacity' => 50, 'room_type' => 'lecture'],
            ['code' => 'LAB-301', 'name' => 'Computer Laboratory 301', 'building' => 'Main', 'floor' => '3', 'capacity' => 45, 'room_type' => 'laboratory'],
            ['code' => 'SEM-101', 'name' => 'Seminar Room 101', 'building' => 'Annex', 'floor' => '1', 'capacity' => 30, 'room_type' => 'seminar'],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(
                ['code' => $room['code']],
                $room + ['status' => 'available'],
            );
        }

        $it101 = Subject::where('code', 'IT101')->firstOrFail();

        SubjectOffering::updateOrCreate(
            [
                'academic_term_id' => $currentTerm->id,
                'section_id' => $bsitSection->id,
                'subject_id' => $it101->id,
            ],
            ['expected_students' => 45, 'status' => 'open'],
        );
    }
}
