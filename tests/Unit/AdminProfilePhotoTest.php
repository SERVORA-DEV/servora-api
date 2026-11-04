<?php

namespace Tests\Unit;

use App\Http\Controllers\System\ProfilePhotoController;
use App\Models\User;
use App\Services\ImageUploadService;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class AdminProfilePhotoTest extends TestCase
{
    private function actor(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['uuid' => 'admin-one', 'role' => 'system_administrator', 'profile_photo' => 'administrators/old']);
        return $user;
    }

    private function request(User $user, bool $valid = true): Request
    {
        $file = UploadedFile::fake()->createWithContent($valid ? 'avatar.png' : 'avatar.txt', $valid
            ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
            : 'not an image');
        $request = Request::create('/api/system/profile/photo', 'POST', [], [], ['photo' => $file]);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    public function test_it_saves_the_authenticated_accounts_photo_before_deleting_the_old_file(): void
    {
        app()->instance(Cloudinary::class, new Cloudinary(['cloud' => ['cloud_name' => 'demo']]));
        $user = $this->actor();
        $user->shouldReceive('saveOrFail')->once()->andReturn(true);
        $images = Mockery::mock(ImageUploadService::class);
        $images->shouldReceive('store')->once()->with(Mockery::type(UploadedFile::class), 'administrators/admin-one/profile')->andReturn('administrators/new');
        $images->shouldReceive('delete')->once()->with('administrators/old')->andReturnUsing(function () use ($user) {
            $this->assertSame('administrators/new', $user->profile_photo);
        });
        $response = (new ProfilePhotoController($images))->store($this->request($user));
        $this->assertStringContainsString('administrators/new', $response->getData(true)['data']['profile_photo']);
    }

    public function test_a_failed_database_save_only_cleans_up_the_new_upload(): void
    {
        $user = $this->actor();
        $user->shouldReceive('saveOrFail')->once()->andThrow(new \RuntimeException('Database unavailable'));
        $images = Mockery::mock(ImageUploadService::class);
        $images->shouldReceive('store')->once()->andReturn('administrators/new');
        $images->shouldReceive('delete')->once()->with('administrators/new');
        $this->expectException(\RuntimeException::class);
        (new ProfilePhotoController($images))->store($this->request($user));
    }

    public function test_non_images_are_rejected_before_upload(): void
    {
        $images = Mockery::mock(ImageUploadService::class);
        $images->shouldNotReceive('store');
        $this->expectException(ValidationException::class);
        (new ProfilePhotoController($images))->store($this->request($this->actor(), false));
    }

    public function test_removal_clears_the_account_before_deleting_its_stored_image(): void
    {
        $user = $this->actor();
        $user->shouldReceive('saveOrFail')->once()->andReturn(true);
        $images = Mockery::mock(ImageUploadService::class);
        $images->shouldReceive('delete')->once()->with('administrators/old')->andReturnUsing(function () use ($user) {
            $this->assertNull($user->profile_photo);
        });
        $response = (new ProfilePhotoController($images))->destroy($this->request($user));
        $this->assertNull($response->getData(true)['data']['profile_photo']);
    }

    public function test_photo_routes_retain_admin_authentication_role_and_two_factor_gates(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => $route->uri() === 'api/system/profile/photo');
        $this->assertCount(2, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:system_administrator', $route->gatherMiddleware());
            $this->assertContains('admin.2fa', $route->gatherMiddleware());
        }
    }
}
