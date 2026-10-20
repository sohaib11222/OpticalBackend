<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetAccountPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct($token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    protected function resetUrl($u)
    {
        $base = $u->account_type === 'admin' ? config('optical.admin_url') : config('optical.shop_url');

        return rtrim($base, '/').'/auth/reset-password?'.http_build_query(['token' => $this->token, 'email' => $u->email]);
    }
}
