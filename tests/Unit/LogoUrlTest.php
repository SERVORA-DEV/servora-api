<?php

namespace Tests\Unit;

use App\Services\ImageUploadService;
use Cloudinary\Cloudinary;
use Tests\TestCase;

// A business logo shown in a round or square slot is delivered as a square with the whole
// image fitted inside, so a portrait or wide logo is not cut off.
//
// No database.
//   php artisan test --filter=LogoUrlTest
class LogoUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(Cloudinary::class, new Cloudinary(['cloud' => ['cloud_name' => 'demo']]));
    }

    public function test_no_logo_gives_no_address(): void
    {
        $this->assertNull(ImageUploadService::logoUrl(null));
        $this->assertNull(ImageUploadService::logoUrl(''));
    }

    public function test_a_logo_is_fitted_inside_a_transparent_square(): void
    {
        $url = ImageUploadService::logoUrl('businesses/abc/logo');

        $this->assertStringContainsString('c_pad', $url);
        $this->assertStringContainsString('w_200', $url);
        $this->assertStringContainsString('h_200', $url);
        $this->assertStringContainsString('b_transparent', $url);
        $this->assertStringContainsString('businesses/abc/logo', $url);
    }

    public function test_the_size_can_be_asked_for(): void
    {
        $this->assertStringContainsString('w_400', ImageUploadService::logoUrl('x', 400));
    }

    public function test_the_original_address_is_unchanged(): void
    {
        $this->assertStringNotContainsString('c_pad', ImageUploadService::url('businesses/abc/logo'));
    }
}
