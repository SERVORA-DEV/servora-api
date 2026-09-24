<?php

namespace Tests\Feature;

use App\Models\SpaBusiness;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

// Covers /business/settings/* — the owner's Business Settings pages on web.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=BusinessSettingsTest
class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create([
            'owner_id' => $this->owner->id,
            'business_email' => 'hello@lotus.test',
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Lotus Spa',
            'legal_name' => 'Lotus Wellness Inc.',
            'spa_type' => 'Day Spa',
            'tagline' => 'Breathe.',
            'business_description' => 'A calm place.',
            'business_email' => 'hello@lotus.test',
            'business_phone' => '+639171234567',
            'head_office_address' => 'Davao City',
            'facebook_url' => 'facebook.com/lotus',
            'instagram_handle' => '@lotus',
            'website_url' => 'lotus.test',
        ], $overrides);
    }

    public function test_show_returns_defaults_for_a_business_that_never_saved(): void
    {
        $this->getJson('/api/business/settings')
            ->assertOk()
            ->assertJsonPath('data.payments.accept_cash', true)
            ->assertJsonPath('data.staff_policy.default_commission_rate', 15)
            ->assertJsonPath('data.booking_defaults.cancellation_tiers.moderate', 50)
            ->assertJsonPath('data.notifications.reminder_timing', 24)
            ->assertJsonPath('data.legal.editable', false);
    }

    public function test_identity_is_saved_and_read_back(): void
    {
        $this->postJson('/api/business/settings/identity', $this->identity())
            ->assertOk()
            ->assertJsonPath('data.identity.legal_name', 'Lotus Wellness Inc.')
            ->assertJsonPath('data.identity.spa_type', 'Day Spa');

        $this->business->refresh();
        $this->assertSame('Davao City', $this->business->head_office_address);
        $this->assertSame('@lotus', $this->business->instagram_handle);
    }

    public function test_identity_rejects_another_businesses_email(): void
    {
        SpaBusiness::factory()->create(['business_email' => 'taken@spa.test']);

        $this->postJson('/api/business/settings/identity', $this->identity(['business_email' => 'taken@spa.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_email');
    }

    public function test_identity_uploads_and_removes_the_logo(): void
    {
        $images = Mockery::mock(ImageUploadService::class);
        $images->shouldReceive('replace')->once()->andReturn('business-logos/new');
        $images->shouldReceive('delete')->once()->with('business-logos/new');
        $this->app->instance(ImageUploadService::class, $images);

        $this->post('/api/business/settings/identity', $this->identity([
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('business-logos/new', $this->business->refresh()->business_logo);

        $this->postJson('/api/business/settings/identity', $this->identity(['remove_logo' => true]))->assertOk();
        $this->assertNull($this->business->refresh()->business_logo);
    }

    public function test_legal_is_locked_once_verified(): void
    {
        $this->patchJson('/api/business/settings/legal', ['registration_document_type' => 'DTI'])
            ->assertStatus(422);
    }

    public function test_legal_is_editable_before_review_and_keeps_business_type_consistent(): void
    {
        $this->business->update(['verification_status' => 'Unregistered', 'business_type' => 'Sole Proprietorship']);

        $this->patchJson('/api/business/settings/legal', [
            'registration_document_type' => 'SEC',
            'registration_number' => 'CS2026',
            'registered_business_name' => 'Lotus Wellness Inc.',
        ])->assertOk()->assertJsonPath('data.legal.registration_number', 'CS2026');

        $this->assertSame('Corporation', $this->business->refresh()->business_type);
    }

    public function test_sections_save_partially_and_store_typed_values(): void
    {
        $this->patchJson('/api/business/settings/booking-defaults', [
            'online_booking_enabled' => false,
            'deposit_percent' => '40',
            'cancellation_tiers' => ['moderate' => '25'],
        ])->assertOk()
            ->assertJsonPath('data.booking_defaults.online_booking_enabled', false)
            ->assertJsonPath('data.booking_defaults.deposit_percent', 40)
            ->assertJsonPath('data.booking_defaults.cancellation_tiers.moderate', 25)
            ->assertJsonPath('data.booking_defaults.cancellation_tiers.flexible', 100)
            ->assertJsonPath('data.booking_defaults.walk_in_enabled', true);

        $this->patchJson('/api/business/settings/staff-policy', ['commission_type' => 'fixed', 'default_commission_rate' => 250])
            ->assertOk()->assertJsonPath('data.staff_policy.default_commission_rate', 250);

        $this->patchJson('/api/business/settings/notifications', ['sms_channel' => true, 'renewal_alert_days' => 14])
            ->assertOk()->assertJsonPath('data.notifications.renewal_alert_days', 14);

        $this->patchJson('/api/business/settings/payments', ['gcash_number' => '09171234567'])
            ->assertOk()->assertJsonPath('data.payments.gcash_number', '09171234567');

        // Earlier sections survive later writes.
        $this->getJson('/api/business/settings')
            ->assertJsonPath('data.booking_defaults.deposit_percent', 40)
            ->assertJsonPath('data.notifications.sms_channel', true);
    }

    public function test_sections_validate_their_values(): void
    {
        $this->patchJson('/api/business/settings/staff-policy', ['commission_type' => 'percentage', 'default_commission_rate' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('default_commission_rate');

        $this->patchJson('/api/business/settings/notifications', ['reminder_timing' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('reminder_timing');

        $this->patchJson('/api/business/settings/payments', array_fill_keys([
            'accept_cash', 'accept_gcash', 'accept_paymaya', 'accept_card', 'accept_xendit', 'accept_bank_transfer',
        ], false))->assertStatus(422)->assertJsonValidationErrors('accept_cash');
    }

    public function test_role_permission_defaults_start_all_on_and_round_trip(): void
    {
        $this->getJson('/api/business/settings')
            ->assertOk()
            ->assertJsonPath('data.role_permissions.manager.staff_create', true)
            ->assertJsonPath('data.role_permissions.front_officer.payment_create', true)
            ->assertJsonMissingPath('data.role_permissions.front_officer.staff_create');

        $this->patchJson('/api/business/settings/staff-policy', [
            'late_threshold_minutes' => 15,
            'role_permissions' => [
                'manager' => ['staff_create' => false],
                'front_officer' => ['payment_create' => false],
            ],
        ])->assertOk()
            ->assertJsonPath('data.staff_policy.late_threshold_minutes', 15)
            ->assertJsonPath('data.role_permissions.manager.staff_create', false)
            ->assertJsonPath('data.role_permissions.manager.staff_view', true)
            ->assertJsonPath('data.role_permissions.front_officer.payment_create', false);

        // A later partial save keeps earlier choices.
        $this->patchJson('/api/business/settings/staff-policy', ['role_permissions' => ['manager' => ['staff_view' => false]]])
            ->assertOk()
            ->assertJsonPath('data.role_permissions.manager.staff_create', false)
            ->assertJsonPath('data.role_permissions.manager.staff_view', false);
    }

    public function test_role_permissions_reject_keys_outside_the_roles_bundle(): void
    {
        // Front officers can never manage staff, so it can't be a default.
        $this->patchJson('/api/business/settings/staff-policy', ['role_permissions' => ['front_officer' => ['staff_create' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('role_permissions.front_officer');

        $this->patchJson('/api/business/settings/staff-policy', ['role_permissions' => ['business_owner' => ['staff_view' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('role_permissions');
    }

    public function test_managers_cannot_change_business_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));

        $this->patchJson('/api/business/settings/payments', ['accept_card' => true])->assertForbidden();
    }
}
