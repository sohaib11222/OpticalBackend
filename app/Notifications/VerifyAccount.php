<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsBrandedMail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyAccount extends VerifyEmail implements ShouldQueue
{
    use BuildsBrandedMail, Queueable;

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

    public function toMail($notifiable): MailMessage
    {
        return $this->brandedMail([
            'subject' => 'Conferma il tuo indirizzo email',
            'preheader' => 'Conferma il tuo indirizzo email per attivare il tuo account.',
            'headline' => 'Conferma il tuo indirizzo email',
            'greeting' => 'Ciao '.$notifiable->name.',',
            'body' => 'Per completare l’attivazione del tuo account Vista Express 360, conferma il tuo indirizzo email.',
            'action_label' => 'Conferma email',
            'action_url' => $this->verificationUrl($notifiable),
            'note' => 'Se non hai creato tu questo account, puoi ignorare questa email.',
        ]);
    }
}
