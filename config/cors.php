<?php

return ['paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], 'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174'))), 'allowed_origins_patterns' => [], 'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With'], 'exposed_headers' => ['X-Request-ID'], 'max_age' => 600, 'supports_credentials' => true];
