<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Review;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// Covers the owner's Branch Settings endpoints (web /business/settings/branch/*)
// and how they reach the public client-app API.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=BranchSettingsTest
class BranchSettingsTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'latitude' => 7.0731,
            'longitude' => 125.6128,
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $path = ''): string
    {
        return "/api/business/branch/{$this->branch->uuid}{$path}";
    }

    private function fakeImages(): void
    {
        $images = Mockery::mock(ImageUploadService::class);
        $n = 0;
        $images->shouldReceive('store')->andReturnUsing(function () use (&$n) { return 'gallery/p'.(++$n); });
        $images->shouldReceive('delete');
        $this->app->instance(ImageUploadService::class, $images);
    }

    public function test_social_links_save_with_the_branch_details(): void
    {
        $this->patchJson($this->url(), [
            'facebook_url' => 'facebook.com/lotus-lanang',
            'instagram_handle' => '@lotuslanang',
            'website_url' => 'lotus.test/lanang',
        ])->assertOk()
            ->assertJsonPath('data.facebook_url', 'facebook.com/lotus-lanang')
            ->assertJsonPath('data.instagram_handle', '@lotuslanang');
    }

    public function test_marketplace_starts_from_defaults_and_saves(): void
    {
        $this->getJson($this->url('/marketplace'))
            ->assertOk()
            ->assertJsonPath('data.listing_visible', true)
            ->assertJsonPath('data.display.show_prices', true)
            ->assertJsonPath('data.display.review_sort', 'newest')
            ->assertJsonPath('data.photos', []);

        $this->patchJson($this->url('/marketplace'), [
            'promo_text' => '  Book 2, get 1 free  ',
            'highlights' => ['Private Rooms', 'Private Rooms', ' Parking '],
            'display' => ['show_prices' => false, 'review_sort' => 'highest'],
        ])->assertOk()
            ->assertJsonPath('data.promo_text', 'Book 2, get 1 free')
            ->assertJsonPath('data.highlights', ['Private Rooms', 'Parking'])
            ->assertJsonPath('data.display.show_prices', false)
            ->assertJsonPath('data.display.show_reviews', true)
            ->assertJsonPath('data.display.review_sort', 'highest');
    }

    public function test_marketplace_validates(): void
    {
        $this->patchJson($this->url('/marketplace'), [
            'promo_text' => str_repeat('x', 81),
            'display' => ['review_sort' => 'random'],
        ])->assertStatus(422)->assertJsonValidationErrors(['promo_text', 'display.review_sort']);
    }

    public function test_a_hidden_branch_disappears_from_the_client_app(): void
    {
        $this->makeBookable($this->branch, withService: true);

        $this->getJson('/api/spas/nearby?lat=7.0731&lng=125.6128')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/spas/{$this->branch->uuid}")->assertOk();

        $this->patchJson($this->url('/marketplace'), ['listing_visible' => false])->assertOk();

        $this->getJson('/api/spas/nearby?lat=7.0731&lng=125.6128')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/spas/{$this->branch->uuid}")->assertNotFound();
    }

    public function test_photo_gallery_upload_cover_and_delete(): void
    {
        $this->fakeImages();

        $response = $this->post($this->url('/photos'), [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $photos = $response->json('data.photos');
        $this->assertCount(2, $photos);
        $this->assertTrue($photos[0]['is_cover'], 'first photo becomes the cover');
        $this->assertFalse($photos[1]['is_cover']);

        $this->postJson($this->url("/photos/{$photos[1]['uuid']}/cover"))
            ->assertOk()
            ->assertJsonPath('data.photos.0.is_cover', false)
            ->assertJsonPath('data.photos.1.is_cover', true);

        // Deleting the cover promotes the remaining photo.
        $this->deleteJson($this->url("/photos/{$photos[1]['uuid']}"))
            ->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.is_cover', true);
    }

    public function test_the_gallery_order_can_be_changed(): void
    {
        $this->fakeImages();
        $photos = $this->post($this->url('/photos'), [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->json('data.photos');
        [$a, $b, $c] = array_column($photos, 'uuid');

        $this->patchJson($this->url('/photos/order'), ['photos' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('data.photos.0.uuid', $c)
            ->assertJsonPath('data.photos.1.uuid', $a)
            ->assertJsonPath('data.photos.2.uuid', $b)
            // Moving a photo doesn't change which one is the cover.
            ->assertJsonPath('data.photos.1.is_cover', true);

        $this->getJson($this->url('/marketplace'))->assertOk()->assertJsonPath('data.photos.0.uuid', $c);
    }

    public function test_an_order_that_is_not_the_whole_gallery_is_refused(): void
    {
        $this->fakeImages();
        $photos = $this->post($this->url('/photos'), [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->json('data.photos');

        $this->patchJson($this->url('/photos/order'), ['photos' => [$photos[0]['uuid']]])->assertStatus(422);
        $this->patchJson($this->url('/photos/order'), ['photos' => [$photos[0]['uuid'], (string) \Illuminate\Support\Str::uuid()]])->assertStatus(422);
    }

    // The "Branch Photo" from registration is kept on the branch itself, so the
    // gallery used to open empty for a branch that already had a picture.
    public function test_the_registration_photo_joins_the_gallery_as_its_cover(): void
    {
        $this->branch->update(['cover_photo' => 'branches/b/registration']);

        $this->getJson($this->url('/marketplace'))
            ->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.is_cover', true);

        // Taken in once, not on every visit.
        $this->getJson($this->url('/marketplace'))->assertOk()->assertJsonCount(1, 'data.photos');
        $this->assertSame('branches/b/registration', $this->branch->fresh()->coverPhoto->path);
    }

    public function test_the_registration_photo_does_not_replace_a_chosen_cover(): void
    {
        $this->fakeImages();
        $this->post($this->url('/photos'), ['photos' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertOk();
        $this->branch->update(['cover_photo' => 'branches/b/registration']);

        $photos = $this->getJson($this->url('/marketplace'))->assertOk()->json('data.photos');

        $this->assertCount(2, $photos);
        $this->assertSame([false, true], array_column($photos, 'is_cover'), 'the registration photo is first, the chosen cover stays');
    }

    public function test_removing_the_registration_photo_does_not_bring_it_back(): void
    {
        $this->fakeImages();
        $this->branch->update(['cover_photo' => 'branches/b/registration']);
        $photo = $this->getJson($this->url('/marketplace'))->json('data.photos.0');

        $this->deleteJson($this->url("/photos/{$photo['uuid']}"))->assertOk()->assertJsonCount(0, 'data.photos');

        $this->assertNull($this->branch->fresh()->cover_photo);
        $this->getJson($this->url('/marketplace'))->assertOk()->assertJsonCount(0, 'data.photos');
    }

    public function test_gallery_is_capped_at_eight_photos(): void
    {
        $this->fakeImages();
        $files = fn ($n) => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, $n));

        $this->post($this->url('/photos'), ['photos' => $files(6)], ['Accept' => 'application/json'])->assertOk();
        $this->post($this->url('/photos'), ['photos' => $files(3)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'A branch can have up to 8 photos — you can add 2 more.');
    }

    public function test_the_gallery_cover_is_the_public_cover_photo(): void
    {
        $this->fakeImages();
        $this->post($this->url('/photos'), ['photos' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertOk();

        $this->branch->refresh();
        $this->assertSame('gallery/p1', $this->branch->coverPhoto->path);

        $this->getJson("/api/spas/{$this->branch->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data.listing.photos');
    }

    public function test_booking_overrides_replace_the_whole_map(): void
    {
        $this->patchJson($this->url('/booking-policy'), ['overrides' => [
            'walk_in_enabled' => false,
            'max_service_minutes_per_booking' => '120',
        ]])->assertOk()
            ->assertJsonPath('data.booking_overrides.walk_in_enabled', false)
            ->assertJsonPath('data.booking_overrides.max_service_minutes_per_booking', 120);

        // Only the time limit left → walk-ins follow the default again.
        $this->patchJson($this->url('/booking-policy'), ['overrides' => ['max_service_minutes_per_booking' => 90]])
            ->assertOk()
            ->assertJsonMissingPath('data.booking_overrides.walk_in_enabled')
            ->assertJsonPath('data.booking_overrides.max_service_minutes_per_booking', 90);

        $this->patchJson($this->url('/booking-policy'), ['overrides' => []])->assertOk();
        $this->assertNull($this->branch->fresh()->booking_overrides);
    }

    public function test_booking_overrides_validate_keys_and_values(): void
    {
        $this->patchJson($this->url('/booking-policy'), ['overrides' => ['queue_enabled' => true]])
            ->assertStatus(422)->assertJsonValidationErrors('overrides');

        $this->patchJson($this->url('/booking-policy'), ['overrides' => ['max_service_minutes_per_booking' => 5000]])
            ->assertStatus(422)->assertJsonValidationErrors('overrides.max_service_minutes_per_booking');
    }

    public function test_reviews_summary_counts_only_this_branches_published_reviews(): void
    {
        $client = Client::factory()->create(['spa_business_id' => $this->business->id, 'first_name' => 'Ana', 'last_name' => 'Reyes']);
        $other = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        $review = function (SpaBranch $branch, int $rating, string $status = 'Published', bool $anonymous = false) use ($client) {
            $appointment = Appointment::create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => 'T-'.uniqid(),
                'appointment_date' => '2026-09-01',
                'appointment_time' => '10:00',
                'appointment_type' => 'Reservation',
                'source' => 'Mobile',
                'status' => Appointment::STATUS_SCHEDULED,
            ]);
            Review::create(['appointment_id' => $appointment->id, 'client_id' => $client->id, 'rating' => $rating, 'status' => $status, 'is_anonymous' => $anonymous]);
        };

        $review($this->branch, 5);
        $review($this->branch, 4, anonymous: true);
        $review($this->branch, 1, 'Hidden');
        $review($other, 1);

        $response = $this->getJson($this->url('/reviews-summary'))
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.breakdown.0', ['stars' => 5, 'count' => 1])
            ->assertJsonPath('data.breakdown.4', ['stars' => 1, 'count' => 0]);

        $this->assertEquals(4.5, $response->json('data.average'));
        $this->assertEqualsCanonicalizing(['Ana Reyes', 'Anonymous'], collect($response->json('data.reviews'))->pluck('client_name')->all());
    }

    public function test_another_businesses_branch_is_not_found(): void
    {
        $foreign = SpaBranch::factory()->create();

        $this->getJson("/api/business/branch/{$foreign->uuid}/marketplace")->assertNotFound();
        $this->patchJson("/api/business/branch/{$foreign->uuid}/booking-policy", ['overrides' => []])->assertNotFound();
    }
}
