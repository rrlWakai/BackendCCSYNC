<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_api_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'CCSync API is healthy.',
                'data' => [
                    'status' => 'ok',
                    'application' => config('app.name'),
                    'version' => config('app.api_version'),
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['status', 'application', 'version', 'timestamp'],
            ]);
    }
}
