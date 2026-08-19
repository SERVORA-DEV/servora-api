<?php

return [

    // Applies to every module using ImageUploadService — bump per-env via
    // .env, no code changes needed.
    'max_size_kb' => (int) env('UPLOAD_MAX_SIZE_KB', 5120),

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Checked against UploadedFile::getMimeType() (the detected type, not
    // the client-supplied one) as a second layer behind each FormRequest's
    // own 'image'/'mimes' validation rule.
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Used by DocumentUploadService (private-disk uploads: government ID,
    // face-scan frames, business registration documents) — a separate,
    // wider set than the image-only config above since business
    // registration documents (DTI/SEC certificates) may be PDFs.
    'document_max_size_kb' => (int) env('UPLOAD_DOCUMENT_MAX_SIZE_KB', 8192),

    'document_allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    'document_allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],

];
