<?php

namespace App\Services;

use Cloudinary\Asset\DeliveryType;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

// Private-document counterpart to ImageUploadService, for files that must
// never be publicly reachable — government IDs, live face-scan captures,
// and business registration (DTI/SEC) documents. Uploads to Cloudinary with
// type => 'authenticated' (Cloudinary explicitly supports PDFs under
// resource_type 'image' — that's the images-AND-documents type; 'raw' is
// for arbitrary non-renderable files — so no special-casing is needed for
// the business registration document, which allows PDF). Stores/returns the
// Cloudinary public_id (not a local path). Retrieval is a signed(),
// authenticated-type delivery URL built directly from that public_id — see
// signedUrl() — there is no local retrieval route/controller for these
// anymore; Cloudinary itself is the "authenticated endpoint".
class DocumentUploadService
{
    private const RESOURCE_TYPE = 'image';

    public function __construct(private Cloudinary $cloudinary) {}

    /**
     * Uploads $file to Cloudinary under {$folder}/ with a generated public_id
     * (the client's original filename is never trusted). Returns the
     * Cloudinary public_id to save in the DB.
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $this->assertValid($file);

        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'type' => 'authenticated',
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
     * existing document.
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
            'type' => 'authenticated',
            'resource_type' => self::RESOURCE_TYPE,
            'invalidate' => true,
        ]);
    }

    /**
     * Builds a signed, authenticated-delivery Cloudinary URL for the given
     * public_id — the sole way any of these files are ever viewable. The
     * signature (HMAC over the asset + API secret) can't be forged without
     * the Cloudinary API secret; unlike the local signed routes this
     * replaces, it does not expire (Cloudinary's simple sign_url mechanism
     * has no timestamp component — true time-limited access requires
     * Cloudinary's separate paid Access Control/auth-token feature), but it
     * remains just as unguessable, satisfying the "never expose these files
     * publicly, authenticated access only" requirement.
     */
    public function signedUrl(?string $publicId): ?string
    {
        if (! $publicId) {
            return null;
        }

        return (string) $this->cloudinary->image($publicId)
            ->deliveryType(DeliveryType::AUTHENTICATED)
            ->signUrl()
            ->toUrl();
    }

    /**
     * Defense-in-depth behind the FormRequest's own 'mimes' rule —
     * re-checks the actual detected MIME type (not the client-supplied one)
     * and size against config/uploads.php's document_* keys.
     */
    private function assertValid(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file failed to transfer correctly.');
        }

        $allowedMimes = config('uploads.document_allowed_mimes', []);
        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            throw new InvalidArgumentException('Unsupported file type.');
        }

        $maxSizeKb = (int) config('uploads.document_max_size_kb', 8192);
        if ($file->getSize() > $maxSizeKb * 1024) {
            throw new InvalidArgumentException('File exceeds the maximum allowed size.');
        }
    }
}
