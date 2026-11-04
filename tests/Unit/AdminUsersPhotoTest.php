<?php

namespace Tests\Unit;

use App\Http\Resources\AdminUsersResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\AdminUsersRepository;
use App\Service\NotificationService;
use App\Service\System\AdminUsersService;
use App\Services\ImageUploadService;
use App\Support\AdminPermissions;
use App\Support\ProfilePhoto;
use Cloudinary\Cloudinary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUsersPhotoTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        // No project database, migrations, or Cloudinary writes in these tests.
        config([
            'database.default' => 'admin_photo_test',
            'database.connections.admin_photo_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ],
        ]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('role');
            $table->string('audience');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('account_status')->default('Active');
            $table->text('profile_photo')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            foreach (AdminPermissions::keys() as $key) {
                $table->boolean($key)->default(false);
            }
            $table->timestamps();
        });
        app()->instance(Cloudinary::class, new Cloudinary(['cloud' => ['cloud_name' => 'demo']]));
    }

    private function user(?string $photo = 'administrators/old'): User
    {
        return User::create([
            'role' => 'system_administrator', 'first_name' => 'Photo', 'last_name' => 'Test',
            'email' => 'photo@example.test', 'password' => 'test-password',
            'account_status' => 'Active', 'profile_photo' => $photo,
        ]);
    }

    private function service(?AdminUsersRepository $repository = null): AdminUsersService
    {
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('administratorCreated')->andReturnNull();
        $audit = Mockery::mock(AuditLogRepository::class)->makePartial();
        $audit->shouldReceive('recordAdminAction')->andReturn(new AuditLog());

        return new AdminUsersService($repository ?? new AdminUsersRepository(), $notifications, $audit);
    }

    private function images(): \Mockery\MockInterface
    {
        $images = Mockery::mock(ImageUploadService::class);
        app()->instance(ImageUploadService::class, $images);

        return $images;
    }

    public function test_management_list_and_detail_return_cdn_urls_without_changing_stored_ids(): void
    {
        $user = $this->user();
        $expected = ProfilePhoto::url('administrators/old');
        $this->assertStringStartsWith('https://res.cloudinary.com/demo/image/upload/', $expected);
        $request = Request::create('/api/system/admin/user-management');
        $this->assertSame($expected, $this->service()->getAdminUsers($user->uuid)->resolve($request)['profile_photo']);
        $this->assertSame($expected, $this->service()->listAdminUsers()->resolve($request)[0]['profile_photo']);
        $this->assertSame('administrators/old', $user->fresh()->profile_photo);
    }

    public function test_legacy_links_inline_photos_and_missing_photos_remain_readable(): void
    {
        foreach ([null, '', 'https://example.test/avatar.png', 'data:image/png;base64,'.self::PNG] as $stored) {
            $user = new User(['profile_photo' => $stored]);
            $user->setRelation('permission', null);
            $result = (new AdminUsersResource($user))->resolve(Request::create('/'));
            $this->assertSame($stored ?: null, $result['profile_photo']);
        }
    }

    public function test_create_uploads_validated_bytes_and_saves_an_id_that_survives_a_refresh(): void
    {
        $images = $this->images();
        $images->shouldReceive('store')->once()->withArgs(function (UploadedFile $file, string $folder) {
            $this->assertSame(base64_decode(self::PNG), file_get_contents($file->getRealPath()));
            $this->assertSame('image/png', $file->getMimeType());

            return $folder === 'administrators/profile';
        })->andReturn('administrators/new');
        $images->shouldNotReceive('delete');
        $resource = $this->service()->createAdminUsers([
            'first_name' => 'Photo', 'last_name' => 'Test', 'email' => 'new@example.test',
            'password' => 'test-password', 'profile_photo' => 'data:image/png;base64,'.self::PNG,
        ]);
        $saved = User::firstOrFail();
        $this->assertSame('administrators/new', $saved->fresh()->profile_photo);
        $this->assertSame(ProfilePhoto::url('administrators/new'), $resource->resolve(Request::create('/'))['profile_photo']);
        $this->assertNotNull($saved->email_verified_at);
        $this->assertNotNull($saved->permission);
    }

    public function test_replacement_saves_the_new_id_before_removing_the_previous_image(): void
    {
        $user = $this->user();
        $images = $this->images();
        $images->shouldReceive('store')->once()->with(Mockery::type(UploadedFile::class), "administrators/{$user->uuid}/profile")->andReturn('administrators/new');
        $images->shouldReceive('delete')->once()->with('administrators/old')->andReturnUsing(function () use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('administrators/new', $user->fresh()->profile_photo);
        });
        $this->service()->updateAdminUsers($user->uuid, ['profile_photo' => 'data:image/png;base64,'.self::PNG]);
        $this->assertSame(ProfilePhoto::url('administrators/new'), $this->service()->getAdminUsers($user->uuid)->resolve(Request::create('/'))['profile_photo']);
    }

    public function test_unchanged_url_or_omitted_photo_never_rewrites_the_stored_id(): void
    {
        $user = $this->user();
        $images = $this->images();
        $images->shouldNotReceive('store');
        $images->shouldNotReceive('delete');
        $this->service()->updateAdminUsers($user->uuid, ['profile_photo' => ProfilePhoto::url($user->profile_photo)]);
        $this->service()->updateAdminUsers($user->uuid, ['first_name' => 'Changed']);
        $this->assertSame('administrators/old', $user->fresh()->profile_photo);
        $this->assertSame('Changed', $user->fresh()->first_name);
    }

    public function test_removal_persists_before_cleanup_and_remains_removed_after_refresh(): void
    {
        $user = $this->user();
        $images = $this->images();
        $images->shouldNotReceive('store');
        $images->shouldReceive('delete')->once()->with('administrators/old')->andReturnUsing(fn () => $this->assertNull($user->fresh()->profile_photo));
        $this->service()->updateAdminUsers($user->uuid, ['profile_photo' => null]);
        $this->assertNull($this->service()->getAdminUsers($user->uuid)->resolve(Request::create('/'))['profile_photo']);
    }

    public function test_failed_update_rolls_back_the_photo_and_only_cleans_up_the_new_upload(): void
    {
        $user = $this->user();
        $images = $this->images();
        $images->shouldReceive('store')->once()->andReturn('administrators/new');
        $images->shouldReceive('delete')->once()->with('administrators/new');
        $repository = Mockery::mock(AdminUsersRepository::class)->makePartial();
        $repository->shouldReceive('update')->once()->andReturnUsing(function ($uuid, $payload) {
            (new AdminUsersRepository())->update($uuid, $payload);
            throw new \RuntimeException('Simulated permission save failure');
        });
        try {
            $this->service($repository)->updateAdminUsers($user->uuid, ['profile_photo' => 'data:image/png;base64,'.self::PNG]);
            $this->fail('Expected a save failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated permission save failure', $e->getMessage());
            $this->assertSame('administrators/old', $user->fresh()->profile_photo);
        }
    }

    public function test_failed_create_does_not_leave_a_photo_or_partial_account(): void
    {
        $images = $this->images();
        $images->shouldReceive('store')->once()->andReturn('administrators/new');
        $images->shouldReceive('delete')->once()->with('administrators/new');
        $repository = Mockery::mock(AdminUsersRepository::class)->makePartial();
        $repository->shouldReceive('createPermission')->once()->andThrow(new \RuntimeException('Simulated permission save failure'));
        try {
            $this->service($repository)->createAdminUsers([
                'email' => 'new@example.test', 'password' => 'test-password', 'profile_photo' => 'data:image/png;base64,'.self::PNG,
            ]);
            $this->fail('Expected a save failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated permission save failure', $e->getMessage());
            $this->assertSame(0, User::count());
        }
    }

    public static function invalidPhotos(): array
    {
        return [
            'invalid base64' => ['data:image/png;base64,###'],
            'disguised text' => ['data:image/png;base64,'.base64_encode('not an image')],
            'unsupported SVG' => ['data:image/svg+xml;base64,'.base64_encode('<svg/>')],
            'arbitrary URL' => ['https://example.test/new.png'],
            'private resource path' => ['identity-verifications/private-document'],
        ];
    }

    #[DataProvider('invalidPhotos')]
    public function test_invalid_new_images_are_rejected_before_any_upload(string $photo): void
    {
        $user = $this->user();
        $images = $this->images();
        $images->shouldNotReceive('store');
        $images->shouldNotReceive('delete');
        try {
            $this->service()->updateAdminUsers($user->uuid, ['profile_photo' => $photo]);
            $this->fail('Expected image validation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('profile_photo', $e->errors());
            $this->assertSame('administrators/old', $user->fresh()->profile_photo);
        }
    }

    public function test_oversized_images_are_rejected_before_upload(): void
    {
        config(['uploads.max_size_kb' => 1]);
        $images = $this->images();
        $images->shouldNotReceive('store');
        $this->expectException(ValidationException::class);
        $this->service()->updateAdminUsers($this->user()->uuid, ['profile_photo' => 'data:image/png;base64,'.base64_encode(str_repeat('x', 1025))]);
    }
}
