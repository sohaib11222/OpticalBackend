<?php

return [
    'shop_url' => env('SHOP_URL', 'http://localhost:5173/pulse-ui'),
    'admin_url' => env('ADMIN_URL', 'http://localhost:5174'),
    'registration_requires_approval' => (bool) env('REGISTRATION_REQUIRES_APPROVAL', true),
    'email_verification_required' => (bool) env('EMAIL_VERIFICATION_REQUIRED', true),
    // A password change requires a code delivered to the account's existing
    // mailbox. These short values limit the usefulness of a copied code.
    'password_change_code_minutes' => (int) env('PASSWORD_CHANGE_CODE_MINUTES', 15),
    'password_change_code_attempts' => (int) env('PASSWORD_CHANGE_CODE_ATTEMPTS', 5),
    'password_change_code_resend_seconds' => (int) env('PASSWORD_CHANGE_CODE_RESEND_SECONDS', 60),
    // Rolling, persistent cookie sessions. Set a positive cap for a stricter deployment.
    'absolute_session_minutes' => (int) env('AUTH_ABSOLUTE_MINUTES', 0),
];
