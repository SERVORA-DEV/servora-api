<?php

namespace App\Support;

use App\Services\ImageUploadService;

// A person's profile picture lives in users.profile_photo. What is stored there
// depends on where it came from: a Cloudinary public_id for a picture uploaded
// from the app (ImageUploadService), or a data URL / link for older ones (the
// owner's profile). This turns any of them into something a screen can show.
class ProfilePhoto
{
    /** The address to show for a stored value, or null when there is none. */
    public static function url(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        if (static::isExternal($stored)) {
            return $stored;
        }

        return ImageUploadService::url($stored);
    }

    /**
     * A stored value that is not on our Cloudinary account (a data URL or a
     * link): there is no file of ours to delete when it is replaced or removed.
     */
    public static function isExternal(?string $stored): bool
    {
        return $stored !== null && (str_starts_with($stored, 'data:') || str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://'));
    }

    /**
     * What one spa shows for a client: their picture, unless the spa's staff
     * dismissed this very picture. A new upload is a different value, so it shows.
     */
    public static function forSpa(?string $stored, ?string $dismissed): ?string
    {
        if (blank($stored) || ($dismissed !== null && $dismissed === $stored)) {
            return null;
        }

        return static::url($stored);
    }
}
