<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

class VerifyAccount extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function verificationUrl($u)
    {
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $u->id, 'hash' => sha1($u->getEmailForVerification())], false);
        $base = $u->account_type === 'admin' ? config('optical.admin_url') : config('optical.shop_url');

        return rtrim($base, '/').'/auth/verify-email?'.http_build_query(['url' => $signed]);
    }
}
