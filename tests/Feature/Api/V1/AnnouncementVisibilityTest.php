<?php

namespace Tests\Feature\Api\V1;

use App\Models\Announcement;
use App\Models\AnnouncementTarget;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AnnouncementVisibilityTest extends TestCase
{
    use CreatesAcademicData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_feed_excludes_scheduled_and_expired_announcements(): void
    {
        $actor = $this->makeUser(Role::STUDENT);
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $visible = $this->makeAnnouncement($author, [
            'published_at' => CarbonImmutable::now()->subDay(),
        ]);
        $scheduled = $this->makeAnnouncement($author, [
            'published_at' => CarbonImmutable::now()->addHour(),
        ]);
        $expired = $this->makeAnnouncement($author, [
            'published_at' => CarbonImmutable::now()->subDay(),
            'expires_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($actor)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonPath('data.announcements.0.id', $visible->id);

        $this->getJson('/api/v1/announcements/'.$scheduled->id)->assertForbidden();
        $this->getJson('/api/v1/announcements/'.$expired->id)->assertForbidden();
    }

    public function test_drafts_and_archived_announcements_never_enter_the_feed(): void
    {
        $actor = $this->makeUser(Role::STUDENT);
        $author = $this->makeUser(Role::ADMINISTRATOR);

        $draft = $this->makeAnnouncement($author, [
            'status' => 'draft',
            'published_at' => null,
        ]);
        $archived = $this->makeAnnouncement($author, [
            'status' => 'archived',
            'published_at' => CarbonImmutable::now()->subDay(),
            'archived_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($actor)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');

        $this->getJson('/api/v1/announcements/'.$draft->id)->assertForbidden();
        $this->getJson('/api/v1/announcements/'.$archived->id)->assertForbidden();
    }

    public function test_administrators_can_view_targeted_announcements_but_not_scheduled_ones(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $author = $this->makeUser(Role::STUDENT);
        $targeted = $this->makeAnnouncement($author, [
            'audience_scope' => 'custom',
        ]);
        AnnouncementTarget::create([
            'announcement_id' => $targeted->id,
            'role_id' => $this->makeRole(Role::STUDENT)->id,
        ]);
        $scheduled = $this->makeAnnouncement($author, [
            'published_at' => CarbonImmutable::now()->addHour(),
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonPath('data.announcements.0.id', $targeted->id);

        $this->getJson('/api/v1/announcements/'.$targeted->id)->assertOk();
        $this->getJson('/api/v1/announcements/'.$scheduled->id)->assertForbidden();
    }

    public function test_custom_audience_targets_are_applied_to_the_feed(): void
    {
        $student = $this->makeUser(Role::STUDENT);
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $matching = $this->makeAnnouncement($author, [
            'audience_scope' => 'custom',
        ]);
        $notMatching = $this->makeAnnouncement($author, [
            'audience_scope' => 'custom',
        ]);
        AnnouncementTarget::create([
            'announcement_id' => $matching->id,
            'role_id' => $student->role_id,
        ]);
        AnnouncementTarget::create([
            'announcement_id' => $notMatching->id,
            'role_id' => $this->makeRole(Role::PROFESSOR)->id,
        ]);

        $this->actingAs($student)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonPath('data.announcements.0.id', $matching->id);

        $this->getJson('/api/v1/announcements/'.$notMatching->id)->assertForbidden();
    }

    public function test_students_and_professors_cannot_access_announcement_management(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $announcement = $this->makeAnnouncement($author, [
            'status' => 'draft',
            'published_at' => null,
        ]);
        $payload = [
            'title' => 'Updated title',
            'content' => 'Updated content.',
            'category' => 'general',
            'audience_scope' => 'all',
        ];

        foreach ([Role::STUDENT, Role::PROFESSOR] as $role) {
            $actor = $this->makeUser($role);

            $this->actingAs($actor)
                ->getJson('/api/v1/announcements/manage')
                ->assertForbidden();
            $this->postJson('/api/v1/announcements', $payload)->assertForbidden();
            $this->patchJson('/api/v1/announcements/'.$announcement->id, $payload)
                ->assertForbidden();
            $this->postJson('/api/v1/announcements/'.$announcement->id.'/publish')
                ->assertForbidden();
            $this->postJson('/api/v1/announcements/'.$announcement->id.'/archive')
                ->assertForbidden();
            $this->getJson('/api/v1/announcements/'.$announcement->id.'/history')
                ->assertForbidden();
        }
    }

    public function test_department_personnel_cannot_manage_announcements_outside_assigned_programs(): void
    {
        $assignedProgram = $this->makeProgram();
        $otherProgram = $this->makeProgram();
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $departmentPersonnel->departmentPersonnelPrograms()->attach($assignedProgram->id);
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $announcement = $this->makeAnnouncement($author, [
            'audience_scope' => 'custom',
            'status' => 'draft',
            'published_at' => null,
        ]);
        AnnouncementTarget::create([
            'announcement_id' => $announcement->id,
            'program_id' => $otherProgram->id,
        ]);
        AnnouncementTarget::create([
            'announcement_id' => $announcement->id,
            'program_id' => $assignedProgram->id,
        ]);

        $this->actingAs($departmentPersonnel)
            ->getJson('/api/v1/announcements/manage')
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');
        $this->patchJson('/api/v1/announcements/'.$announcement->id, [
            'title' => 'Out-of-scope edit',
        ])->assertForbidden();
        $this->postJson('/api/v1/announcements/'.$announcement->id.'/publish')
            ->assertForbidden();
        $this->postJson('/api/v1/announcements/'.$announcement->id.'/archive')
            ->assertForbidden();
        $this->getJson('/api/v1/announcements/'.$announcement->id.'/history')
            ->assertForbidden();

        $this->assertDatabaseMissing('announcement_history', [
            'announcement_id' => $announcement->id,
            'acted_by_user_id' => $departmentPersonnel->id,
        ]);
    }

    private function makeAnnouncement(User $author, array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'author_user_id' => $author->id,
            'title' => 'Visibility test announcement',
            'summary' => null,
            'content' => 'Announcement content.',
            'category' => 'general',
            'priority' => 'normal',
            'audience_scope' => 'all',
            'status' => 'published',
            'published_at' => CarbonImmutable::now(),
            'expires_at' => null,
            'archived_at' => null,
        ], $attributes));
    }
}
