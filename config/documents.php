<?php

return ['scanner' => env('FILE_SCANNER', 'clamav'), 'clamd_address' => env('CLAMD_ADDRESS', 'tcp://127.0.0.1:3310'), 'max_bytes' => 10 * 1024 * 1024, 'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'docx', 'xlsx']];
