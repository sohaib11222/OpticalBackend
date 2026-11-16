<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short-lived code is deliberately sent synchronously so the UI never claims it was sent when SMTP rejected it. */
class VerifyPasswordChangeCode extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code, private readonly int $minutes)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your password change')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use this verification code to change the password for your Vista Express 360 account:')
            ->line('# '.$this->code)
            ->line('This code expires in '.$this->minutes.' minutes. If you did not request it, you can ignore this email.');
    }
}
