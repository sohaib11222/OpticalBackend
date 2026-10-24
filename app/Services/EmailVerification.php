<?php

namespace App\Services;

use App\Models\User;

class EmailVerification
{
    public static function required(?User $user = null): bool
    {
        // Platform administrators are provisioned privately, not through public signup.
        if ($user?->account_type === 'admin') {
            return false;
        }
        // A copied development flag can never disable verification in production/staging.
        return ! app()->environment(['local', 'testing']) || (bool) config('optical.email_verification_required', true);
    }
}
