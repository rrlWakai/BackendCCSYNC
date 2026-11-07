<?php

namespace Tests\Feature\Database;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\YearLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class CheckConstraintTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_year_level_is_limited_to_the_supported_range(): void
    {
        $this->assertQueryFails(fn () => YearLevel::create([
            'level' => 7,
            'name' => 'Seventh Year',
        ]));
    }

    public function test_academic_term_dates_must_be_in_order(): void
    {
        $this->assertQueryFails(fn () => AcademicTerm::create([
            'academic_year' => '2027-2028',
            'term' => 'first',
            'name' => 'Invalid Term',
            'starts_on' => '2027-12-01',
            'ends_on' => '2027-08-01',
            'is_current' => false,
            'status' => 'planned',
        ]));
    }

    public function test_section_capacity_subject_units_and_room_capacity_must_be_positive(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();

        $this->assertQueryFails(fn () => $this->makeSection($program, $term, null, [
            'capacity' => 0,
        ]));

        $this->assertQueryFails(fn () => Subject::create([
            'code' => 'INVALID-UNITS',
            'title' => 'Invalid Units',
            'units' => 0,
            'lecture_hours' => 0,
            'lab_hours' => 0,
            'status' => 'active',
        ]));

        $this->assertQueryFails(fn () => Room::create([
            'code' => 'INVALID-CAPACITY',
            'name' => 'Invalid Capacity',
            'capacity' => 0,
            'room_type' => 'lecture',
            'status' => 'available',
        ]));
    }

    public function test_class_schedule_day_and_time_must_be_valid(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);

        $this->assertQueryFails(fn () => ClassSchedule::create([
            'subject_offering_id' => $offering->id,
            'day_of_week' => 8,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'schedule_type' => 'lecture',
            'modality' => 'face_to_face',
            'status' => 'draft',
        ]));

        $this->assertQueryFails(fn () => ClassSchedule::create([
            'subject_offering_id' => $offering->id,
            'day_of_week' => 1,
            'start_time' => '10:00:00',
            'end_time' => '10:00:00',
            'schedule_type' => 'lecture',
            'modality' => 'face_to_face',
            'status' => 'draft',
        ]));
    }
}
