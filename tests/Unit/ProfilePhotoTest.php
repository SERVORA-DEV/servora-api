<?php

namespace Tests\Unit;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use App\Support\ProfilePhoto;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// What a client's profile picture looks like to a spa: their own picture, unless
// the spa's staff took that picture down; a newer upload shows again.
//
// No database.
//   php artisan test --filter=ProfilePhotoTest
class ProfilePhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(Cloudinary::class, new Cloudinary(['cloud' => ['cloud_name' => 'demo']]));
        // The migration "has run": no schema lookup, so no database.
        Cache::forever('schema:client_photo_dismissal', true);
        Cache::forever('schema:client_profile', true);
    }

    private function resourceFor(?string $stored, ?string $dismissed): array
    {
        $client = new Client(['uuid' => 'c1', 'first_name' => 'Nicole', 'last_name' => 'A', 'user_id' => 1, 'dismissed_photo' => $dismissed]);
        $client->setRelation('user', new User(['profile_photo' => $stored]));

        return (new ClientResource($client))->toArray(Request::create('/'))['account'];
    }

    public function test_nothing_stored_shows_nothing(): void
    {
        $this->assertNull(ProfilePhoto::url(null));
        $this->assertNull(ProfilePhoto::url('  '));
    }

    public function test_a_data_url_or_a_link_is_shown_as_it_is(): void
    {
        $this->assertSame('data:image/png;base64,AAAA', ProfilePhoto::url('data:image/png;base64,AAAA'));
        $this->assertSame('https://example.com/a.jpg', ProfilePhoto::url('https://example.com/a.jpg'));
    }

    public function test_a_cloudinary_id_becomes_its_address(): void
    {
        $url = ProfilePhoto::url('clients/u1/profile/abc');
        $this->assertStringContainsString('res.cloudinary.com/demo', $url);
        $this->assertStringContainsString('clients/u1/profile/abc', $url);
    }

    public function test_only_our_own_files_have_something_to_delete(): void
    {
        $this->assertFalse(ProfilePhoto::isExternal('clients/u1/profile/abc'));
        $this->assertTrue(ProfilePhoto::isExternal('data:image/png;base64,AAAA'));
        $this->assertTrue(ProfilePhoto::isExternal('https://example.com/a.jpg'));
    }

    public function test_a_dismissed_picture_is_hidden_but_a_newer_one_shows(): void
    {
        $this->assertNull(ProfilePhoto::forSpa('clients/u1/profile/old', 'clients/u1/profile/old'));
        $this->assertNotNull(ProfilePhoto::forSpa('clients/u1/profile/new', 'clients/u1/profile/old'));
        $this->assertNotNull(ProfilePhoto::forSpa('clients/u1/profile/old', null));
    }

    public function test_the_client_profile_carries_the_avatar_unless_dismissed(): void
    {
        $this->assertNotNull($this->resourceFor('clients/u1/profile/a', null)['avatar']);
        $this->assertNull($this->resourceFor('clients/u1/profile/a', 'clients/u1/profile/a')['avatar']);
        $this->assertNotNull($this->resourceFor('clients/u1/profile/b', 'clients/u1/profile/a')['avatar']);
        $this->assertNull($this->resourceFor(null, null)['avatar']);
    }
}
