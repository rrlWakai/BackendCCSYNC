<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\Major;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AcademicClassPreparationTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_preparation_creates_required_classes_once_and_keeps_them_as_drafts(): void
    {
        $term = $this->makeAcademicTerm();
        $bsit = $this->makeProgram(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $bscs = $this->makeProgram(['code' => 'BSCS', 'name' => 'BS Computer Science']);
        $yearOne = $this->makeYearLevel(1);
        $yearThree = $this->makeYearLevel(3);
        $major = Major::create([
            'program_id' => $bsit->id,
            'code' => 'NET',
            'name' => 'Networking',
            'status' => 'active',
        ]);
        $common = $this->makeSubject(['code' => 'IT101']);
        $majorSubject = $this->makeSubject(['code' => 'IT301']);
        $csSubject = $this->makeSubject(['code' => 'CS101']);
        $bsitOne = $this->makeSection($bsit, $term, $yearOne, ['code' => 'BSIT-1A']);
        $bsitThree = $this->makeSection($bsit, $term, $yearThree, [
            'code' => 'BSIT-3A',
            'major_id' => $major->id,
        ]);
        $this->makeSection($bscs, $term, $yearOne, ['code' => 'BSCS-1A']);

        $this->makeCurriculumRequirement($bsit, $yearOne, $common);
        $this->makeCurriculumRequirement($bsit, $yearThree, $common);
        $this->makeCurriculumRequirement($bsit, $yearThree, $majorSubject, [
            'major_id' => $major->id,
            'classification' => 'major',
        ]);
        $this->makeCurriculumRequirement($bscs, $yearOne, $csSubject);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->getJson('/api/v1/academic/subject-offerings/preparation-plan?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.preparation.summary.required', 4)
            ->assertJsonPath('data.preparation.summary.missing', 4)
            ->assertJsonPath('data.preparation.summary.sections', 3);

        $this->postJson('/api/v1/academic/subject-offerings/prepare', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 4)
            ->assertJsonPath('data.preparation.summary.existing', 4)
            ->assertJsonPath('data.preparation.summary.missing', 0);

        $this->assertDatabaseCount('subject_offerings', 4);
        $this->assertDatabaseHas('subject_offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $bsitOne->id,
            'subject_id' => $common->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('subject_offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $bsitThree->id,
            'subject_id' => $majorSubject->id,
            'status' => 'draft',
        ]);

        $this->postJson('/api/v1/academic/subject-offerings/prepare', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.preparation.summary.existing', 4);

        $this->assertDatabaseCount('subject_offerings', 4);
    }

    public function test_preparation_reports_blocked_sections_and_only_creates_valid_classes(): void
    {
        $term = $this->makeAcademicTerm();
        $bsit = $this->makeProgram(['code' => 'BSIT']);
        $bscs = $this->makeProgram(['code' => 'BSCS']);
        $yearOne = $this->makeYearLevel(1);
        $yearThree = $this->makeYearLevel(3);
        $subject = $this->makeSubject();
        $this->makeSection($bsit, $term, $yearOne);
        $this->makeSection($bsit, $term, $yearThree);
        $this->makeSection($bscs, $term, $yearOne);
        $this->makeCurriculumRequirement($bsit, $yearOne, $subject);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $response = $this->getJson('/api/v1/academic/subject-offerings/preparation-plan?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.preparation.summary.required', 1)
            ->assertJsonPath('data.preparation.summary.sections_with_issues', 2)
            ->assertJsonPath('data.preparation.summary.issues', 2);

        $codes = collect($response->json('data.preparation.issues'))->pluck('code');
        $this->assertTrue($codes->contains('missing_major'));
        $this->assertTrue($codes->contains('curriculum_missing'));

        $this->postJson('/api/v1/academic/subject-offerings/prepare', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1);

        $this->assertDatabaseCount('subject_offerings', 1);
    }

    public function test_preparation_warns_about_inactive_curriculum_subjects(): void
    {
        $term = $this->makeAcademicTerm();
        $bsit = $this->makeProgram(['code' => 'BSIT']);
        $this->makeProgram(['code' => 'BSCS']);
        $yearOne = $this->makeYearLevel(1);
        $section = $this->makeSection($bsit, $term, $yearOne);
        $activeSubject = $this->makeSubject(['code' => 'ACT101']);
        $inactiveSubject = $this->makeSubject(['code' => 'INA101', 'status' => 'inactive']);
        $this->makeCurriculumRequirement($bsit, $yearOne, $activeSubject);
        $this->makeCurriculumRequirement($bsit, $yearOne, $inactiveSubject);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->getJson('/api/v1/academic/subject-offerings/preparation-plan?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.preparation.summary.required', 1)
            ->assertJsonPath('data.preparation.summary.warnings', 1)
            ->assertJsonPath('data.preparation.warnings.0.code', 'subject_inactive');

        $this->postJson('/api/v1/academic/subject-offerings/prepare', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1);

        $this->assertDatabaseHas('subject_offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $activeSubject->id,
        ]);
        $this->assertDatabaseMissing('subject_offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $inactiveSubject->id,
        ]);
    }

    public function test_preparation_rejects_archived_terms(): void
    {
        $archived = AcademicTerm::create([
            'academic_year' => '2025-2026',
            'term' => 'first',
            'name' => 'Archived Semester',
            'starts_on' => '2025-08-01',
            'ends_on' => '2025-12-19',
            'is_current' => false,
            'status' => 'archived',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/academic/subject-offerings/prepare', [
            'academic_term_id' => $archived->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_term_id');
    }
}
