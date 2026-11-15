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
    'product_images_sync' => filter_var(env('PRODUCT_IMAGES_SYNC', true), FILTER_VALIDATE_BOOL),

    // Private documents and import files use the same hardened scanner as
    // queued jobs, but complete in the request by default.  This prevents a
    // healthy upload from remaining "Pending" merely because a worker was
    // restarted or a queue heartbeat is delayed.  Operators handling very
    // large files may deliberately set either value to false.
    'private_files_sync' => filter_var(env('PRIVATE_FILES_SYNC', true), FILTER_VALIDATE_BOOL),
    'customer_imports_sync' => filter_var(env('CUSTOMER_IMPORTS_SYNC', true), FILTER_VALIDATE_BOOL),
];
