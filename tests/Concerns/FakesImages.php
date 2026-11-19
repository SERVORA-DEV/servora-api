<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

// A real (1×1 PNG) image upload. UploadedFile::fake()->image() draws its picture with PHP's GD
// extension, which not every machine has; this needs nothing, and still passes the 'image' and
// 'mimes' validation rules.
trait FakesImages
{
    protected function fakeImage(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='
        ));
    }
}
