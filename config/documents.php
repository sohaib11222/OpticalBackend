<?php

return [
    'scanner' => env('FILE_SCANNER', 'clamav'),
    'clamd_address' => env('CLAMD_ADDRESS', 'tcp://127.0.0.1:3310'),
    'max_bytes' => 10 * 1024 * 1024,
    'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'docx', 'xlsx'],

    // Product photographs are small, user-facing files. Process them during
    // the upload request so a successful upload is immediately selectable in
    // the catalogue and Vista publisher. Existing queued files and retries
    // still use the uploads worker when this is deliberately disabled.
    'product_images_sync' => env('PRODUCT_IMAGES_SYNC', true),
];
