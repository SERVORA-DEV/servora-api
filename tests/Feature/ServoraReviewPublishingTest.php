<?php

namespace Tests\Feature;

use App\Models\ServoraReview;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Owners' reviews of SERVORA go on the public Reviews page at once (an edit
// too); administrators can hide one, and a hidden one stays hidden when its
// owner edits it. Reviews left waiting from when each had to be checked show.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class ServoraReviewPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->owner = User::factory()->create(['role' => 'business_owner', 'first_name' => 'Alessia', 'last_name' => 'Moretti', 'account_status' => 'Active']);
        SpaBusiness::factory()->create(['owner_id' => $this->owner->id, 'verification_status' => 'Verified', 'business_name' => 'Aqua-Marine Waves']);
    }

    private function review(array $over = []): array
    {
        return array_merge(['rating' => 5, 'title' => 'Runs our front desk', 'body' => 'Bookings, the queue and billing in one place. Our staff learned it in a day.', 'show_business' => true], $over);
    }

    private function publicPage(): array
    {
        Cache::flush();

        return $this->getJson('/api/servora-reviews')->assertOk()->json();
    }

    public function test_a_review_shows_on_the_page_as_soon_as_it_is_sent(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/business/servora-review', $this->review())
            ->assertOk()
            ->assertJsonPath('data.status', 'Published')
            ->assertJsonPath('message', 'Thank you! Your review is now on our Reviews page.');

        $page = $this->publicPage();
        $this->assertSame(1, $page['summary']['count']);
        $this->assertSame('Alessia M.', $page['data'][0]['author']);
        $this->assertSame('Aqua-Marine Waves', $page['data'][0]['business']);
    }

    public function test_an_edit_replaces_the_words_at_once_and_says_edited(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/business/servora-review', $this->review());

        $this->putJson('/api/business/servora-review', $this->review(['rating' => 4, 'body' => 'Still great, though I would like more report filters for my branches.']))->assertOk();

        $row = $this->publicPage()['data'][0];
        $this->assertSame(4, $row['rating']);
        $this->assertStringStartsWith('Still great', $row['body']);
        $this->assertNotNull($row['edited_at']);
    }

    public function test_a_hidden_review_stays_hidden_when_edited(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/business/servora-review', $this->review());
        ServoraReview::query()->update(['status' => 'Hidden']);

        $this->putJson('/api/business/servora-review', $this->review(['body' => 'Changed my words a little, but it is the same review as before.']))
            ->assertOk()
            ->assertJsonPath('data.status', 'Hidden');

        $this->assertSame(0, $this->publicPage()['summary']['count']);
    }

    public function test_an_administrator_takes_an_inappropriate_review_down_and_can_show_it_again(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/business/servora-review', $this->review());
        $uuid = ServoraReview::sole()->uuid;
        $this->assertSame(1, $this->publicPage()['summary']['count']);

        config(['admin_access.require_two_factor' => false, 'admin_access.allowed_ips' => []]);
        $admin = User::factory()->create(['role' => 'system_administrator', 'account_status' => 'Active', 'email_verified_at' => now()]);
        \App\Models\UserPermission::create(['user_id' => $admin->id, ...array_fill_keys(\App\Support\Access\AdminPermissions::keys(), true)]);
        Sanctum::actingAs($admin->fresh('permission'));

        $this->postJson("/api/system/servora-reviews/{$uuid}/hide")->assertOk();
        $this->assertSame(0, $this->getJson('/api/servora-reviews')->json('summary.count'));

        $this->postJson("/api/system/servora-reviews/{$uuid}/publish")->assertOk();
        $this->assertSame(1, $this->getJson('/api/servora-reviews')->json('summary.count'));
    }

    public function test_a_review_left_waiting_from_before_shows_too(): void
    {
        ServoraReview::create([
            'user_id' => $this->owner->id,
            'spa_business_id' => SpaBusiness::first()->id,
            'rating' => 5,
            'body' => 'Sent when every review still waited for a check first.',
            'show_business' => true,
            'status' => 'Pending',
        ]);

        $page = $this->publicPage();

        $this->assertSame(1, $page['summary']['count']);
        $this->assertNotNull($page['data'][0]['published_at']);
    }
}
