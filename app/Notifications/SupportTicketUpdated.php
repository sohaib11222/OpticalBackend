<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email only the shop user who created a ticket; never its other members. */
class SupportTicketUpdated extends Notification implements ShouldQueue
{
    use BuildsBrandedMail, Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly int $ticketId,
        private readonly string $reference,
        private readonly string $subjectLine,
        private readonly string $kind,
        private readonly ?string $reply = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isReply = $this->kind === 'admin_reply';
        $statusChanged = $this->kind === 'ticket_status_changed';
        $body = $isReply
            ? 'Il team di assistenza ha risposto alla tua richiesta.'
            : 'Lo stato della tua richiesta di assistenza è stato aggiornato.';

        if ($this->reply !== null && trim($this->reply) !== '') {
            $body .= "\n\nMessaggio del supporto:\n".trim($this->reply);
        }

        return $this->brandedMail([
            'subject' => ($isReply ? 'Nuova risposta' : 'Aggiornamento').': ticket '.$this->reference,
            'preheader' => 'Aggiornamento relativo al ticket '.$this->reference.'.',
            'headline' => $isReply ? 'Hai ricevuto una risposta' : 'Il ticket è stato aggiornato',
            'greeting' => 'Ciao '.$notifiable->name.',',
            'body' => $body.'\n\nRiferimento ticket: '.$this->reference.'\nOggetto: '.$this->subjectLine,
            'action_label' => 'Apri ticket',
            'action_url' => rtrim((string) config('optical.shop_url'), '/').'/support?ticket='.$this->ticketId,
            'note' => $statusChanged ? 'Accedi al ticket per visualizzare tutti i dettagli e rispondere, se necessario.' : 'Accedi al ticket per leggere la conversazione completa e rispondere.',
        ]);
    }
}
