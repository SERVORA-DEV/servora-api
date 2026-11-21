<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// "Send a test pop-up" (POST /push/test): sends to the caller's own devices
// only, says what happened, and never needs the real push services to say so.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class PushTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No keys: nothing leaves this machine.
        config(['push.web.public_key' => null, 'push.web.private_key' => null, 'push.fcm.project_id' => null, 'push.fcm.credentials_json' => null, 'push.fcm.credentials' => null]);
    }

    private function device(User $user, string $kind): PushSubscription
    {
        $endpoint = $kind === PushSubscription::KIND_WEB ? 'https://push.example.com/'.uniqid() : 'fcm-token-'.uniqid();

        return PushSubscription::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'p256dh' => $kind === PushSubscription::KIND_WEB ? 'key' : null,
            'auth' => $kind === PushSubscription::KIND_WEB ? 'auth' : null,
        ]);
    }

    public function test_with_no_device_it_says_so_and_still_lists_the_test(): void
    {
        $owner = User::factory()->create(['role' => 'business_owner']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/push/test')
            ->assertOk()
            ->assertJsonPath('data.sent', false)
            ->assertJsonPath('data.devices', 0)
            ->assertJsonPath('data.results.0', 'No browser or phone has pop-ups on for this account.');

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('title', 'Test pop-up')->count());
    }

    public function test_a_server_without_keys_says_which_half_is_missing(): void
    {
        $owner = User::factory()->create(['role' => 'business_owner']);
        $this->device($owner, PushSubscription::KIND_WEB);
        $this->device($owner, PushSubscription::KIND_FCM);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/push/test')->assertOk();

        $response->assertJsonPath('data.sent', false)
            ->assertJsonPath('data.devices', 2)
            ->assertJsonPath('data.web_ready', false)
            ->assertJsonPath('data.fcm_ready', false);
        $results = implode("\n", $response->json('data.results'));
        $this->assertStringContainsString('Browsers skipped: this server has no VAPID keys', $results);
        $this->assertStringContainsString('Phones skipped: this server has no Firebase key', $results);
    }

    public function test_it_only_ever_reaches_the_caller(): void
    {
        $owner = User::factory()->create(['role' => 'business_owner']);
        $other = User::factory()->create(['role' => 'business_owner']);
        $this->device($other, PushSubscription::KIND_WEB);
        Sanctum::actingAs($owner);

        $this->postJson('/api/push/test')->assertOk()->assertJsonPath('data.devices', 0);
        $this->assertSame(0, Notification::where('user_id', $other->id)->count());
    }

    public function test_it_needs_a_signed_in_account(): void
    {
        $this->postJson('/api/push/test')->assertUnauthorized();
    }
}
