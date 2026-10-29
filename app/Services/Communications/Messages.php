<?php

namespace App\Services\Communications;

use App\Models\CommunicationConsent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\MessageEvent;
use App\Models\MessageTemplate;
use App\Models\Reminder;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Operations\Access;
use App\Services\Operations\ReminderPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Messages
{
    public const VARIABLES = ['first_name', 'last_name', 'shop_name', 'due_date', 'reference'];

    public const PENDING = ['scheduled', 'blocked', 'retry_wait'];

    public static function recipient(Customer $customer, string $channel): string
    {
        $value = $channel === 'email' ? strtolower(trim($customer->email ?? '')) : preg_replace('/[\s().-]/', '', $customer->phone ?? '');
        abort_unless($channel === 'email' ? filter_var($value, FILTER_VALIDATE_EMAIL) : preg_match('/^\+[1-9][0-9]{7,14}$/D', $value), 422, 'Add a valid customer email or international phone number before messaging.');

        return $value;
    }

    public static function recipientHash(string $recipient): string
    {
        return hash_hmac('sha256', strtolower($recipient), config('app.key'));
    }

    public function recipientAllowed(Customer $c, string $channel, string $purpose, string $recipient): bool
    {
        return ! DB::table('messaging_suppressions')->where('channel', $channel)->where('recipient_hash', self::recipientHash($recipient))->exists()
            // No opt-in record is required. Preserve explicit withdrawals and provider opt-outs.
            && ! CommunicationConsent::where('customer_id', $c->id)->where('channel', $channel)->where('purpose', $purpose)->where('granted', false)->where('recipient_hash', self::recipientHash($recipient))->exists();
    }

    public function queue(array $d, int $actor, ?Reminder $reminder = null, ?string $dedupe = null): CustomerMessage
    {
        return DB::transaction(function () use ($d, $actor, $reminder, $dedupe) {
            $shop = ShopLock::acquire();
            $key = $dedupe ?? 'manual:'.$d['creation_key'];
            $hash = hash('sha256', json_encode($d));
            if ($old = CustomerMessage::where('dedupe_key', $key)->first()) {
                abort_unless($dedupe || hash_equals($old->request_hash, $hash), 409, 'This message request key was already used for different details.');

                return $old;
            }
            $c = Customer::findOrFail($d['customer_id']);
            abort_unless($c->status === 'active', 409, 'Restore this customer before messaging.');
            $template = empty($d['template_id']) ? null : MessageTemplate::where('active', true)->findOrFail($d['template_id']);
            $channel = $template?->channel ?? $d['channel'];
            $purpose = $template?->purpose ?? $d['purpose'];
            abort_if($channel === 'whatsapp' && ! $template?->content_sid, 422, 'WhatsApp requires an approved provider template.');
            $recipient = self::recipient($c, $channel);
            abort_unless($this->recipientAllowed($c, $channel, $purpose, $recipient), 422, 'This recipient has opted out of these messages.');
            $variables = ['first_name' => $c->first_name, 'last_name' => $c->last_name, 'shop_name' => $shop->name, 'due_date' => $reminder?->due_on?->toDateString() ?? '', 'reference' => $reminder?->order?->number ?? ''];
            $render = function (string $text) use ($variables) {
                return preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', function ($m) use ($variables) {
                    abort_unless(array_key_exists($m[1], $variables), 422, 'Unknown template variable.');

                    return $variables[$m[1]];
                }, $text);
            };
            $body = $render($template?->body ?? $d['body']);
            $subject = $render($template?->subject ?? $d['subject'] ?? '');
            abort_if($channel === 'email' && trim($subject) === '', 422, 'Email subject is required.');
            $content = [];
            foreach ($template?->content_variables ?? [] as $keyName => $variable) {
                $content[(string) $keyName] = $variables[$variable] ?? '';
            }
            $scheduled = ! empty($d['scheduled_at']) ? CarbonImmutable::parse($d['scheduled_at'])->utc() : CarbonImmutable::now()->addMinutes((int) ($d['delay_minutes'] ?? 0));
            $m = CustomerMessage::create(['customer_id' => $c->id, 'public_id' => (string) Str::uuid(), 'dedupe_key' => $key, 'request_hash' => $hash, 'channel' => $channel, 'purpose' => $purpose, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'content_sid' => $template?->content_sid, 'content_variables' => $content, 'template_id' => $template?->id, 'reminder_id' => $reminder?->id, 'scheduled_at' => $scheduled, 'created_by' => $actor]);
            $this->event($m, 'scheduled');
            Audit::record('message.scheduled', $shop->id, $actor, 'message', $m->id);

            return $m->refresh();
        }, 3);
    }

    public function eligible(CustomerMessage $m): ?string
    {
        if (! Access::can('communications.send') || ! Access::can('customers.read') || ($m->reminder && ! Access::canReadReminder($m->reminder))) {
            return 'permission_revoked';
        }
        $c = $m->customer;
        if (! $c || $c->status !== 'active') {
            return 'customer_archived';
        }
        try {
            $current = self::recipient($c, $m->channel);
        } catch (\Throwable $e) {
            return 'recipient_changed';
        }
        if ($current !== $m->recipient) {
            return 'recipient_changed';
        }
        if (! $this->recipientAllowed($c, $m->channel, $m->purpose, $m->recipient)) {
            return 'recipient_opted_out';
        }
        if ($m->template_id && ! MessageTemplate::whereKey($m->template_id)->where('active', true)->exists()) {
            return 'template_inactive';
        }
        if ($m->reminder_id && (! $m->reminder || ! app(ReminderPlanner::class)->valid($m->reminder))) {
            return 'source_changed';
        }

        return null;
    }

    public function event(CustomerMessage $m, string $status, ?string $code = null, ?string $reason = null, ?int $actor = null): void
    {
        MessageEvent::create(['message_id' => $m->id, 'status' => $status, 'code' => $code, 'reason' => $reason, 'actor_id' => $actor]);
    }

    public function providerStatus(CustomerMessage $m, string $status, ?string $code = null): void
    {
        $mapped = ['queued' => 'accepted', 'accepted' => 'accepted', 'sending' => 'accepted', 'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', 'undelivered' => 'undelivered'];
        if (! isset($mapped[$status])) {
            return;
        }
        DB::transaction(function () use ($m, $mapped, $status, $code) {
            ShopLock::acquire();
            $m = CustomerMessage::lockForUpdate()->findOrFail($m->id);
            if ($code === '21610') {
                DB::table('messaging_suppressions')->updateOrInsert(['channel' => $m->channel, 'recipient_hash' => self::recipientHash($m->recipient)], ['created_at' => now()]);
            }
            $next = $mapped[$status];
            $rank = ['sending' => 0, 'uncertain' => 0, 'accepted' => 1, 'sent' => 2, 'failed' => 3, 'undelivered' => 3, 'delivered' => 4, 'read' => 5];
            if (! isset($rank[$m->status]) || $rank[$next] <= $rank[$m->status]) {
                return;
            }
            $m->forceFill(['status' => $next, 'error_code' => $code, 'error_message' => in_array($next, ['failed', 'undelivered']) ? 'The provider reported delivery failure.' : null, 'delivered_at' => in_array($next, ['delivered', 'read']) ? ($m->delivered_at ?? now()) : null])->save();
            $this->event($m, $next, $code);
        });
    }
}
