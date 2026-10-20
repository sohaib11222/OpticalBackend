<?php

return [
    'shop_url' => env('SHOP_URL', 'http://localhost:5173/pulse-ui'),
    'admin_url' => env('ADMIN_URL', 'http://localhost:5174'),
    'registration_requires_approval' => (bool) env('REGISTRATION_REQUIRES_APPROVAL', true),
    'email_verification_required' => (bool) env('EMAIL_VERIFICATION_REQUIRED', true),
    // Rolling, persistent cookie sessions. Set a positive cap for a stricter deployment.
    'absolute_session_minutes' => (int) env('AUTH_ABSOLUTE_MINUTES', 0),
];
