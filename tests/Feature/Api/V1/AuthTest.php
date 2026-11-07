<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', env('FRONTEND_ADMIN_URL', 'http://localhost:5173'));

        Route::middleware(['auth:sanctum', 'role:administrator'])
            ->get('/api/v1/test/administrator-only', fn () => ApiResponse::success(['ok' => true]));
    }

    public function test_valid_login_returns_the_user_without_a_token_and_regenerates_the_session(): void
    {
        $user = $this->makeUser('administrator', [
            'email' => 'admin@ccsync.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ' ADMIN@CCSYNC.TEST ',
            'password' => 'correct-password',
            'remember' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Signed in.')
            ->assertJsonPath('data.user.email', 'admin@ccsync.test')
            ->assertJsonPath('data.user.role', 'administrator')
            ->assertJsonPath('data.user.profile', null)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_credentials_return_a_generic_401(): void
    {
        $this->makeUser('student', [
            'email' => 'student@ccsync.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ccsync.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertExactJson([
            'success' => false,
            'message' => 'The provided credentials are incorrect.',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@ccsync.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertExactJson([
            'success' => false,
            'message' => 'The provided credentials are incorrect.',
        ]);
    }

    public function test_inactive_and_suspended_accounts_cannot_log_in(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $email = $status.'@ccsync.test';
            $this->makeUser('student', [
                'email' => $email,
                'password' => Hash::make('correct-password'),
                'status' => $status,
            ]);

            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'correct-password',
            ])->assertUnauthorized()->assertJsonPath('success', false);

            $this->assertGuest();
        }
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $this->makeUser('student', [
            'email' => 'student@ccsync.test',
            'password' => Hash::make('correct-password'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'student@ccsync.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ccsync.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)->assertJsonPath('success', false);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_me_returns_the_authenticated_user_and_role_profile(): void
    {
        $program = $this->makeProgram();
        $user = $this->makeUser('professor', ['email' => 'professor@ccsync.test']);
        $this->makeProfessor($user, $program);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('message', 'Authenticated user loaded.')
            ->assertJsonPath('data.user.email', 'professor@ccsync.test')
            ->assertJsonPath('data.user.role', 'professor')
            ->assertJsonPath('data.user.profile.type', 'professor')
            ->assertJsonPath('data.user.profile.program.code', $program->code)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_me_and_logout_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->makeUser('administrator', [
            'email' => 'admin@ccsync.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ccsync.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Signed out.')
            ->assertJsonPath('data', null);

        $this->app['auth']->forgetGuards();

        $this->assertGuest();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivation_invalidates_an_existing_spa_session(): void
    {
        $user = $this->makeUser('administrator', [
            'email' => 'admin@ccsync.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ccsync.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $user->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/schedules')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You do not have permission to access this resource.');

        $user->update(['status' => 'active']);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_validation_failures_use_the_api_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_csrf_cookie_endpoint_issues_an_xsrf_cookie(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_stateful_login_rejects_a_missing_csrf_token(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ccsync.test',
            'password' => 'correct-password',
        ])->assertStatus(419);
    }

    public function test_role_middleware_allows_only_the_configured_active_role(): void
    {
        $administrator = $this->makeUser('administrator');
        $personnel = $this->makeUser('department_personnel');
        $professor = $this->makeUser('professor');
        $student = $this->makeUser('student');
        $inactiveAdministrator = $this->makeUser('administrator', ['status' => 'inactive']);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/test/administrator-only')->assertOk();

        Sanctum::actingAs($personnel);
        $this->getJson('/api/v1/test/administrator-only')->assertForbidden();

        Sanctum::actingAs($professor);
        $this->getJson('/api/v1/test/administrator-only')->assertForbidden();

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/test/administrator-only')->assertForbidden();

        Sanctum::actingAs($inactiveAdministrator);
        $this->getJson('/api/v1/test/administrator-only')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/test/administrator-only')->assertUnauthorized();
    }

    public function test_session_cookie_configuration_is_secure_for_local_spa_auth(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame('localhost', config('session.domain'));
        $this->assertFalse((bool) config('session.secure'));
    }

    public function test_local_spa_origins_are_stateful_and_cors_credentialed(): void
    {
        $this->assertContains('localhost:5173', config('sanctum.stateful'));
        $this->assertContains('localhost:5174', config('sanctum.stateful'));
        $this->assertContains('http://localhost:5173', config('cors.allowed_origins'));
        $this->assertContains('http://localhost:5174', config('cors.allowed_origins'));
        $this->assertContains('api/*', config('cors.paths'));
        $this->assertContains('sanctum/csrf-cookie', config('cors.paths'));
        $this->assertTrue(config('cors.supports_credentials'));
    }
}
