<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class SchedulingReadinessLayersTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_readiness_separates_integration_generation_and_lifecycle_layers(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $this->makeOffering($term, $section, $this->makeSubject());
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $response = $this->getJson(
            '/api/v1/scheduling/readiness?academic_term_id='.$term->id
        )->assertOk();

        $readiness = $response->json('data.readiness');

        $this->assertFalse($readiness['integration_ready']);
        $this->assertFalse($readiness['generation_data_ready']);
        $this->assertFalse($readiness['lifecycle_ready']);
        $this->assertFalse($readiness['generation_available']);

        $this->assertSame(
            ['integration', 'generation_data', 'lifecycle'],
            array_keys($readiness['layers'])
        );

        $integrationCodes = collect($readiness['layers']['integration']['issues'])->pluck('code');
        $generationDataCodes = collect($readiness['layers']['generation_data']['issues'])->pluck('code');
        $lifecycleCodes = collect($readiness['layers']['lifecycle']['issues'])->pluck('code');

        $this->assertTrue(collect($readiness['issues'])->every(
            fn (array $issue): bool => in_array($issue['layer'], ['integration', 'generation_data', 'lifecycle'], true)
        ));
        $this->assertTrue($integrationCodes->contains('scheduler_service_not_configured'));
        $this->assertTrue($integrationCodes->contains('scheduler_api_key_not_configured'));
        $this->assertTrue($generationDataCodes->contains('professor_assignment_missing'));
        $this->assertTrue($generationDataCodes->contains('time_grid_not_configured'));
        $this->assertTrue($generationDataCodes->contains('meeting_plans_not_configured'));
        $this->assertTrue($lifecycleCodes->contains('generation_records_not_configured'));
        $this->assertTrue($lifecycleCodes->contains('snapshot_history_not_configured'));
        $this->assertTrue($lifecycleCodes->contains('candidate_persistence_not_configured'));
        $this->assertFalse($generationDataCodes->contains('generation_records_not_configured'));
    }
}
