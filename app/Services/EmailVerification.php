<?php

namespace App\Services;

class EmailVerification
{
    public static function required(): bool
    {
        // A copied development flag can never disable verification in production/staging.
        return ! app()->environment(['local', 'testing']) || (bool) config('optical.email_verification_required', true);
    }
}
