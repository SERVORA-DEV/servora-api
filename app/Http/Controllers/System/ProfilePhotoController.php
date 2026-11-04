<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\ImageUploadService;
use App\Support\ProfilePhoto;
use Illuminate\Http\Request;

class ProfilePhotoController extends Controller
{
    public function __construct(private ImageUploadService $images) {}

    public function store(Request $request)
    {
        $request->validate(['photo' => [
            'required', 'file', 'image',
            'mimes:'.implode(',', config('uploads.allowed_extensions')),
            'max:'.config('uploads.max_size_kb'),
        ]]);
        // Only the authenticated account; no target user identifier is accepted.
        $user = $request->user();
        $old = $user->profile_photo;
        $path = $this->images->store($request->file('photo'), "administrators/{$user->uuid}/profile");
        try {
            if (! $user->forceFill(['profile_photo' => $path])->saveOrFail()) {
                throw new \RuntimeException('Could not save the profile photo.');
            }
        } catch (\Throwable $e) {
            $this->cleanUp($path);
            throw $e;
        }
        $this->cleanUp($old);

        return response()->json(['data' => ['profile_photo' => ProfilePhoto::url($path)]]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $old = $user->profile_photo;
        if (! $user->forceFill(['profile_photo' => null])->saveOrFail()) {
            throw new \RuntimeException('Could not remove the profile photo.');
        }
        $this->cleanUp($old);

        return response()->json(['data' => ['profile_photo' => null]]);
    }

    private function cleanUp(?string $path): void
    {
        if (! $path || ProfilePhoto::isExternal($path)) {
            return;
        }
        try {
            $this->images->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
