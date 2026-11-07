<?php

namespace Tests\Feature\Api\V1;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class AnnouncementTimezoneTest extends TestCase
{
    use CreatesAcademicData;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_create_interprets_naive_datetime_as_philippine_local_time(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($author)
            ->postJson('/api/v1/announcements', [
                'title' => 'PH schedule',
                'content' => 'Body.',
                'category' => 'general',
                'audience_scope' => 'all',
                'published_at' => '2026-11-07T06:00',
                'expires_at' => '2026-11-08T06:46',
            ])
            ->assertCreated()
            ->assertJsonPath('data.announcement.published_at', '2026-11-06T22:00:00+00:00')
            ->assertJsonPath('data.announcement.expires_at', '2026-11-07T22:46:00+00:00');

        $announcement = Announcement::query()->where('title', 'PH schedule')->firstOrFail();
        $this->assertSame(
            '2026-11-06 22:00:00',
            CarbonImmutable::parse($announcement->getRawOriginal('published_at'))->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-07 22:46:00',
            CarbonImmutable::parse($announcement->getRawOriginal('expires_at'))->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_update_interprets_naive_datetime_as_philippine_local_time(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $announcement = $this->makeAnnouncement($author, [
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($author)
            ->patchJson('/api/v1/announcements/'.$announcement->id, [
                'published_at' => '2026-11-07T06:00',
                'expires_at' => '2026-11-08T06:46',
            ])
            ->assertOk()
            ->assertJsonPath('data.announcement.published_at', '2026-11-06T22:00:00+00:00')
            ->assertJsonPath('data.announcement.expires_at', '2026-11-07T22:46:00+00:00');

        $announcement->refresh();
        $this->assertSame(
            '2026-11-06 22:00:00',
            CarbonImmutable::parse($announcement->getRawOriginal('published_at'))->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_editing_without_schedule_fields_does_not_shift_existing_times(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $publishedAt = CarbonImmutable::parse('2026-11-06T22:00:00Z');
        $expiresAt = CarbonImmutable::parse('2026-11-07T22:46:00Z');
        $announcement = $this->makeAnnouncement($author, [
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
        ]);

        $this->actingAs($author)
            ->patchJson('/api/v1/announcements/'.$announcement->id, [
                'title' => 'Retitled only',
            ])
            ->assertOk()
            ->assertJsonPath('data.announcement.published_at', '2026-11-06T22:00:00+00:00')
            ->assertJsonPath('data.announcement.expires_at', '2026-11-07T22:46:00+00:00');

        $announcement->refresh();
        $this->assertSame(
            '2026-11-06 22:00:00',
            CarbonImmutable::parse($announcement->getRawOriginal('published_at'))->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-07 22:46:00',
            CarbonImmutable::parse($announcement->getRawOriginal('expires_at'))->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_publication_visibility_uses_intended_philippine_local_instant(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $viewer = $this->makeUser(Role::STUDENT);
        // Intended: 2026-11-07 06:00 Asia/Manila == 2026-11-06 22:00:00Z
        $this->actingAs($author)->postJson('/api/v1/announcements', [
            'title' => 'Timed PH',
            'content' => 'Body.',
            'category' => 'general',
            'audience_scope' => 'all',
            'published_at' => '2026-11-07T06:00',
        ])->assertCreated();
        $announcement = Announcement::query()->where('title', 'Timed PH')->firstOrFail();
        $this->actingAs($author)->postJson('/api/v1/announcements/'.$announcement->id.'/publish')->assertOk();

        CarbonImmutable::setTestNow('2026-11-06 21:59:59 UTC');
        $this->actingAs($viewer)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');
        $this->getJson('/api/v1/announcements/'.$announcement->id)->assertForbidden();

        CarbonImmutable::setTestNow('2026-11-06 22:00:00 UTC');
        $this->actingAs($viewer)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonPath('data.announcements.0.id', $announcement->id);
        $this->getJson('/api/v1/announcements/'.$announcement->id)->assertOk();
    }

    public function test_expiration_visibility_uses_intended_philippine_local_instant(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $viewer = $this->makeUser(Role::STUDENT);
        // Intended expires: 2026-11-08 06:46 Asia/Manila == 2026-11-07 22:46:00Z
        $this->actingAs($author)->postJson('/api/v1/announcements', [
            'title' => 'Expiring PH',
            'content' => 'Body.',
            'category' => 'general',
            'audience_scope' => 'all',
            'published_at' => '2026-11-07T06:00',
            'expires_at' => '2026-11-08T06:46',
        ])->assertCreated();
        $announcement = Announcement::query()->where('title', 'Expiring PH')->firstOrFail();
        $this->actingAs($author)->postJson('/api/v1/announcements/'.$announcement->id.'/publish')->assertOk();

        CarbonImmutable::setTestNow('2026-11-07 22:45:59 UTC');
        $this->actingAs($viewer)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements');

        CarbonImmutable::setTestNow('2026-11-07 22:46:00 UTC');
        $this->actingAs($viewer)
            ->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');
        $this->getJson('/api/v1/announcements/'.$announcement->id)->assertForbidden();
    }

    public function test_api_output_is_unambiguous_utc_and_consistent_across_endpoints(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $viewer = $this->makeUser(Role::STUDENT);

        $this->actingAs($author)->postJson('/api/v1/announcements', [
            'title' => 'Consistent PH',
            'content' => 'Body.',
            'category' => 'general',
            'audience_scope' => 'all',
            'published_at' => '2026-11-07T06:00',
        ])->assertCreated();
        $announcement = Announcement::query()->where('title', 'Consistent PH')->firstOrFail();
        $this->actingAs($author)->postJson('/api/v1/announcements/'.$announcement->id.'/publish')->assertOk();

        CarbonImmutable::setTestNow('2026-11-06 22:30:00 UTC');
        $manage = $this->actingAs($author)
            ->getJson('/api/v1/announcements/manage')
            ->assertOk();
        $feed = $this->actingAs($viewer)->getJson('/api/v1/announcements')->assertOk();
        $detail = $this->actingAs($viewer)->getJson('/api/v1/announcements/'.$announcement->id)->assertOk();

        $expected = '2026-11-06T22:00:00+00:00';
        $this->assertSame($expected, $manage->json('data.announcements.0.published_at'));
        $this->assertSame($expected, $feed->json('data.announcements.0.published_at'));
        $this->assertSame($expected, $detail->json('data.announcement.published_at'));
    }

    private function makeAnnouncement(User $author, array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'author_user_id' => $author->id,
            'title' => 'Timezone test announcement',
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

    public function test_expiration_before_publication_is_rejected(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($author)
            ->postJson('/api/v1/announcements', [
                'title' => 'Bad expiry',
                'content' => 'Body.',
                'category' => 'general',
                'audience_scope' => 'all',
                'published_at' => '2026-11-09T14:47',
                'expires_at' => '2026-11-07T02:44',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_expiration_equal_to_publication_is_rejected(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($author)
            ->postJson('/api/v1/announcements', [
                'title' => 'Equal expiry',
                'content' => 'Body.',
                'category' => 'general',
                'audience_scope' => 'all',
                'published_at' => '2026-11-07T06:00',
                'expires_at' => '2026-11-07T06:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_expiration_after_publication_is_accepted(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($author)
            ->postJson('/api/v1/announcements', [
                'title' => 'Good expiry',
                'content' => 'Body.',
                'category' => 'general',
                'audience_scope' => 'all',
                'published_at' => '2026-11-07T06:00',
                'expires_at' => '2026-11-07T06:01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.announcement.expires_at', '2026-11-06T22:01:00+00:00');
    }

    public function test_updating_only_publication_time_is_validated_against_existing_expiration(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $announcement = $this->makeAnnouncement($author, [
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->actingAs($author)->patchJson('/api/v1/announcements/'.$announcement->id, [
            'published_at' => '2026-11-07T06:00', // 06:00 Manila
            'expires_at' => '2026-11-07T08:00',   // 08:00 Manila
        ])->assertOk();

        $this->actingAs($author)
            ->patchJson('/api/v1/announcements/'.$announcement->id, [
                'published_at' => '2026-11-07T09:00', // 09:00 Manila > 08:00 Manila expiry
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_updating_only_expiration_time_is_validated_against_existing_publication(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        $announcement = $this->makeAnnouncement($author, [
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->actingAs($author)->patchJson('/api/v1/announcements/'.$announcement->id, [
            'published_at' => '2026-11-07T06:00', // 06:00 Manila
            'expires_at' => '2026-11-07T08:00',   // 08:00 Manila
        ])->assertOk();

        $this->actingAs($author)
            ->patchJson('/api/v1/announcements/'.$announcement->id, [
                'expires_at' => '2026-11-07T05:00', // 05:00 Manila < 06:00 Manila publish
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_manila_timezone_input_is_converted_before_expiry_comparison(): void
    {
        $author = $this->makeUser(Role::ADMINISTRATOR);
        // 06:30 Manila -> 2026-11-06T22:30:00Z, 07:00 Manila -> 2026-11-06T23:00:00Z => expires before publish, rejected.
        $this->actingAs($author)
            ->postJson('/api/v1/announcements', [
                'title' => 'TZ compare',
                'content' => 'Body.',
                'category' => 'general',
                'audience_scope' => 'all',
                'published_at' => '2026-11-07T07:00',
                'expires_at' => '2026-11-07T06:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);

        $this->postJson('/api/v1/announcements', [
            'title' => 'TZ compare ok',
            'content' => 'Body.',
            'category' => 'general',
            'audience_scope' => 'all',
            'published_at' => '2026-11-07T06:00',
            'expires_at' => '2026-11-07T07:00',
        ])->assertCreated()
            ->assertJsonPath('data.announcement.published_at', '2026-11-06T22:00:00+00:00')
            ->assertJsonPath('data.announcement.expires_at', '2026-11-06T23:00:00+00:00');
    }
}

