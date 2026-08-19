<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

// Single reusable entry point for every module's image upload/replace/delete
// so controllers and *Service classes never touch Storage:: directly. These
// images are meant to be publicly servable (e.g. a service photo shown on a
// client-facing listing) — uploaded to Cloudinary with the default
// type => 'upload' (public CDN delivery), unlike DocumentUploadService's
// private, type => 'authenticated' documents.
class ImageUploadService
{
    private const RESOURCE_TYPE = 'image';

    public function __construct(private Cloudinary $cloudinary) {}

    /**
     * Uploads $file to Cloudinary under {$folder}/ with a generated
     * public_id — the client's original filename/extension is never
     * trusted. Returns the Cloudinary public_id to save in the DB, e.g.
     * "services/uuid".
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $this->assertValid($file);

        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'type' => 'upload',
            'resource_type' => self::RESOURCE_TYPE,
            'folder' => trim($folder, '/'),
            'use_filename' => false,
            'unique_filename' => true,
            'overwrite' => false,
        ]);

        return (string) $result['public_id'];
    }

    /**
     * Stores the new file first and only deletes the old one once the new
     * file is safely on Cloudinary — a failed upload never destroys the
     * existing image.
     */
    public function replace(?string $oldPublicId, UploadedFile $newFile, string $folder): string
    {
        $newPublicId = $this->store($newFile, $folder);

        if ($oldPublicId) {
            $this->delete($oldPublicId);
        }

        return $newPublicId;
    }

    public function delete(?string $publicId): void
    {
        if (! $publicId) {
            return;
        }

        $this->cloudinary->uploadApi()->destroy($publicId, [
            'type' => 'upload',
            'resource_type' => self::RESOURCE_TYPE,
        ]);
    }

    /**
     * Builds the public Cloudinary CDN URL for the given public_id — no
     * signing needed, these assets are public by design. Static (and builds
     * its own lightweight Cloudinary instance rather than using DI) so API
     * Resources, which aren't container-resolved, can call it directly —
     * same reasoning this method already used for Storage::disk() before
     * the Cloudinary switch.
     */
    public static function url(?string $publicId): ?string
    {
        return $publicId ? (string) (new Cloudinary())->image($publicId)->toUrl() : null;
    }

    /**
     * Defense-in-depth behind the FormRequest's own 'image'/'mimes' rules —
     * re-checks the actual detected MIME type (not the client-supplied one)
     * and size against config/uploads.php so this service is safe to call
     * even if a caller forgets to validate first.
     */
    private function assertValid(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file failed to transfer correctly.');
        }

        $allowedMimes = config('uploads.allowed_mimes', []);
        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            throw new InvalidArgumentException('Unsupported image type.');
        }

        $maxSizeKb = (int) config('uploads.max_size_kb', 5120);
        if ($file->getSize() > $maxSizeKb * 1024) {
            throw new InvalidArgumentException('Image exceeds the maximum allowed size.');
        }
    }
}
