<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short-lived code is deliberately sent synchronously so the UI never claims it was sent when SMTP rejected it. */
class VerifyPasswordChangeCode extends Notification
{
    use BuildsBrandedMail, Queueable;

    public function __construct(private readonly string $code, private readonly int $minutes)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail([
            'subject' => 'Codice di verifica per la password',
            'preheader' => 'Usa questo codice per modificare la tua password.',
            'headline' => 'Conferma la modifica della password',
            'greeting' => 'Ciao '.$notifiable->name.',',
            'body' => 'Inserisci il seguente codice in Vista Express 360 per confermare la modifica della password.',
            'code' => $this->code,
            'note' => 'Il codice scade tra '.$this->minutes.' minuti. Se non hai richiesto questa modifica, ignora questa email.',
        ]);
    }
}
