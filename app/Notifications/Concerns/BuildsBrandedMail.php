<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Keeps every system notification visually and linguistically consistent.
 * The message data is deliberately plain text; the Blade template escapes it
 * before rendering so ticket replies and names can never inject email HTML.
 */
trait BuildsBrandedMail
{
    /** @param array<string, mixed> $content */
    protected function brandedMail(array $content): MailMessage
    {
        $brand = (string) config('mail.from.name', 'Vista Express 360');
        $from = (string) config('mail.from.address', '');
        $message = (new MailMessage)
            ->subject((string) $content['subject'])
            ->view('mail.vista-360', [...$content, 'brand' => $brand])
            ->text('mail.vista-360-text', [...$content, 'brand' => $brand]);

        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $message->from($from, $brand);
        }

        $replyTo = (string) config('mail.reply_to.address', '');
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $message->replyTo($replyTo, (string) config('mail.reply_to.name', $brand));
        }

        if (! empty($content['action_url']) && ! empty($content['action_label'])) {
            $message->action((string) $content['action_label'], (string) $content['action_url']);
        }

        return $message;
    }
}
