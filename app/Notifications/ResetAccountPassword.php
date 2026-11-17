<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsBrandedMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetAccountPassword extends ResetPassword implements ShouldQueue
{
    use BuildsBrandedMail, Queueable;

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

    public function toMail($notifiable): MailMessage
    {
        return $this->brandedMail([
            'subject' => 'Reimposta la tua password',
            'preheader' => 'Hai richiesto il ripristino della password.',
            'headline' => 'Reimposta la tua password',
            'greeting' => 'Ciao '.$notifiable->name.',',
            'body' => 'Abbiamo ricevuto una richiesta di ripristino della password del tuo account Vista Express 360.',
            'action_label' => 'Reimposta password',
            'action_url' => $this->resetUrl($notifiable),
            'note' => 'Se non hai richiesto il ripristino, ignora questa email: la tua password rimarrà invariata.',
        ]);
    }
}
