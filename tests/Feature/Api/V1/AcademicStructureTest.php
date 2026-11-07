<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_administrator_can_search_paginate_create_update_and_deactivate_programs(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->makeProgram();

        $created = $this->postJson('/api/v1/academic/programs', [
            'code' => ' bsit ',
            'name' => 'BS Information Technology',
            'description' => 'Technology program',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.program.code', 'BSIT');

        $id = $created->json('data.program.id');
        $this->getJson('/api/v1/academic/programs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.program.id', $id);
        $this->getJson('/api/v1/academic/programs?search=Information&status=active&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.programs.0.id', $id);

        $this->patchJson('/api/v1/academic/programs/'.$id, [
            'name' => 'BS Information Systems',
            'status' => 'inactive',
        ])
            ->assertOk()
            ->assertJsonPath('data.program.name', 'BS Information Systems')
            ->assertJsonPath('data.program.status', 'inactive');
    }

    public function test_programs_reject_duplicate_codes_and_invalid_data(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $program = $this->makeProgram();

        $this->postJson('/api/v1/academic/programs', [
            'code' => strtolower($program->code),
            'name' => 'Duplicate program',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['code']]);

        $this->postJson('/api/v1/academic/programs', [
            'code' => 'TOOLONGPROGRAMCODE',
            'name' => '',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['code', 'name']]);
    }

    public function test_academic_terms_validate_ranges_and_transactionally_switch_current_term(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $first = $this->makeAcademicTerm();

        $created = $this->postJson('/api/v1/academic/terms', [
            'academic_year' => '2027-2028',
            'term' => 'first',
            'name' => 'First Semester',
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20',
            'status' => 'active',
            'is_current' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.term.is_current', true);

        $newTermId = $created->json('data.term.id');
        $this->assertDatabaseHas('academic_terms', [
            'id' => $first->id,
            'is_current' => false,
        ]);

        $this->patchJson('/api/v1/academic/terms/'.$first->id, [
            'is_current' => true,
        ])->assertOk()->assertJsonPath('data.term.is_current', true);
        $this->assertDatabaseHas('academic_terms', [
            'id' => $newTermId,
            'is_current' => false,
        ]);

        $this->postJson('/api/v1/academic/terms', [
            'academic_year' => '2027-2028',
            'term' => 'second',
            'name' => 'Invalid Dates',
            'starts_on' => '2027-12-20',
            'ends_on' => '2027-08-01',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['ends_on']]);

        $this->postJson('/api/v1/academic/terms', [
            'academic_year' => '2027-2029',
            'term' => 'second',
            'name' => 'Invalid Academic Year',
            'starts_on' => '2027-12-20',
            'ends_on' => '2028-04-30',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['academic_year']]);

        $this->postJson('/api/v1/academic/terms', [
            'academic_year' => '2027-2028',
            'term' => 'first',
            'name' => 'Duplicate Term',
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['academic_year']]);
    }

    public function test_only_active_academic_terms_can_be_current(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/academic/terms', [
            'academic_year' => '2027-2028',
            'term' => 'first',
            'name' => 'Planned Current',
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20',
            'status' => 'planned',
            'is_current' => true,
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['is_current']]);
    }

    public function test_year_levels_support_crud_and_reject_duplicate_or_out_of_range_order(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $level = $this->makeYearLevel();

        $this->postJson('/api/v1/academic/year-levels', [
            'level' => 1,
            'name' => 'Freshman',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['level']]);

        $this->postJson('/api/v1/academic/year-levels', [
            'level' => 7,
            'name' => 'Seventh Year',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['level']]);

        $this->patchJson('/api/v1/academic/year-levels/'.$level->id, [
            'name' => 'First Year',
        ])->assertOk()->assertJsonPath('data.year_level.name', 'First Year');
    }

    public function test_subjects_support_search_crud_and_reject_duplicate_codes_and_invalid_units(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $subject = $this->makeSubject();

        $this->postJson('/api/v1/academic/subjects', [
            'code' => $subject->code,
            'title' => 'Duplicate code',
            'units' => 3,
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['code']]);

        $this->postJson('/api/v1/academic/subjects', [
            'code' => 'IT200',
            'title' => 'Invalid units',
            'units' => 0,
            'lecture_hours' => -1,
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['units', 'lecture_hours']]);

        $created = $this->postJson('/api/v1/academic/subjects', [
            'code' => 'it200',
            'title' => 'Systems Analysis',
            'units' => 3,
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.subject.code', 'IT200');

        $id = $created->json('data.subject.id');
        $this->getJson('/api/v1/academic/subjects?search=Systems&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
        $this->patchJson('/api/v1/academic/subjects/'.$id, [
            'title' => 'Advanced Systems Analysis',
        ])->assertOk()->assertJsonPath('data.subject.title', 'Advanced Systems Analysis');
    }

    public function test_sections_validate_relationships_and_unique_codes_per_term(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $yearLevel = $this->makeYearLevel();
        $section = $this->makeSection($program, $term, $yearLevel, ['code' => 'BSIT-1A']);

        $payload = [
            'program_id' => $program->id,
            'year_level_id' => $yearLevel->id,
            'academic_term_id' => $term->id,
            'code' => 'BSIT-1A',
            'name' => 'First Year A',
            'capacity' => 40,
            'status' => 'active',
        ];

        $this->postJson('/api/v1/academic/sections', $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['code']]);

        $payload['code'] = 'BSIT-1B';
        $payload['program_id'] = 999999;
        $this->postJson('/api/v1/academic/sections', $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['program_id']]);

        $inactiveProgram = $this->makeProgram();
        $inactiveProgram->update(['status' => 'inactive']);
        $payload['program_id'] = $inactiveProgram->id;
        $this->postJson('/api/v1/academic/sections', $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['program_id']]);

        $archivedTerm = $this->makeAcademicTerm(false);
        $archivedTerm->update(['status' => 'archived']);
        $payload['program_id'] = $program->id;
        $payload['academic_term_id'] = $archivedTerm->id;
        $this->postJson('/api/v1/academic/sections', $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['academic_term_id']]);

        $payload['program_id'] = $program->id;
        $payload['academic_term_id'] = $term->id;
        $created = $this->postJson('/api/v1/academic/sections', $payload)
            ->assertCreated()
            ->assertJsonPath('data.section.program.id', $program->id)
            ->assertJsonPath('data.section.year_level.id', $yearLevel->id)
            ->assertJsonPath('data.section.academic_term.id', $term->id);

        $this->getJson('/api/v1/academic/sections?program_id='.$program->id.'&academic_term_id='.$term->id.'&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $section->subjectOfferings()->create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject()->id,
            'status' => 'draft',
        ]);

        $this->patchJson('/api/v1/academic/sections/'.$section->id, [
            'program_id' => (string) $program->id,
            'academic_term_id' => (string) $term->id,
            'name' => 'Updated section name',
        ])->assertOk()->assertJsonPath('data.section.name', 'Updated section name');

        $this->patchJson('/api/v1/academic/sections/'.$section->id, [
            'academic_term_id' => AcademicTerm::create([
                'academic_year' => '2028-2029',
                'term' => 'first',
                'name' => 'Another Term',
                'starts_on' => '2028-08-01',
                'ends_on' => '2028-12-20',
                'status' => 'planned',
            ])->id,
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['academic_term_id']]);
    }

    public function test_administrator_and_department_personnel_can_manage_academic_structure(): void
    {
        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $this->getJson('/api/v1/academic/programs')->assertOk();
            $this->getJson('/api/v1/academic/options')->assertOk();
            $this->postJson('/api/v1/academic/programs', [
                'code' => 'P'.strtoupper(fake()->unique()->lexify('??????')),
                'name' => 'Authorized Program',
            ])->assertCreated();
        }
    }

    public function test_professors_and_students_cannot_read_or_manage_academic_structure(): void
    {
        foreach ([Role::PROFESSOR, Role::STUDENT] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $this->getJson('/api/v1/academic/programs')->assertForbidden();
            $this->getJson('/api/v1/academic/options')->assertForbidden();
            $this->postJson('/api/v1/academic/programs', [
                'code' => 'BLOCKED',
                'name' => 'Unauthorized',
            ])->assertForbidden();
        }
    }

    public function test_academic_endpoints_require_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/v1/academic/programs')->assertUnauthorized();
        $this->getJson('/api/v1/academic/terms')->assertUnauthorized();
        $this->getJson('/api/v1/academic/year-levels')->assertUnauthorized();
        $this->getJson('/api/v1/academic/subjects')->assertUnauthorized();
        $this->getJson('/api/v1/academic/sections')->assertUnauthorized();
    }
}
