<?php

namespace App\Console\Commands;

use App\Jobs\Middleware\WithTenant;
use App\Jobs\SendCustomerMessage;
use App\Models\CustomerMessage;
use App\Models\Reminder;
use App\Models\ShopMembership;
use App\Services\Communications\Messages;
use App\Services\Communications\Provider;
use App\Services\Operations\Access;
use App\Services\Operations\ReminderPlanner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RunCustomerOperations extends Command
{
    protected $signature = 'optical:run-operations';

    protected $description = 'Reconcile customer follow-ups and dispatch due messages with tenant and consent checks.';

    public function handle(): int
    {
        foreach (ShopMembership::where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', 'owner'))->with('shop')->lazyById(100) as $owner) {
            if ($owner->shop->status !== 'active') {
                continue;
            }
            try {
                (new WithTenant($owner->shop_id, $owner->user_id))->handle($this, function () use ($owner) {
                    app(ReminderPlanner::class)->refresh();
                    $tz = $owner->shop->timezone;
                    foreach (Reminder::where('status', 'open')->whereNotNull('template_id')->whereNotNull('customer_id')->lazyById(100) as $reminder) {
                        $at = CarbonImmutable::parse($reminder->due_on->toDateString().' 09:00:00', $tz)->subDays($reminder->lead_days)->utc();
                        if ($at->isFuture()) {
                            continue;
                        }
                        // A completed or submitted occurrence never silently becomes a second send.
                        if (CustomerMessage::where('reminder_id', $reminder->id)->whereNotIn('status', ['cancelled'])->exists()) {
                            continue;
                        }
                        try {
                            (new WithTenant($owner->shop_id, $reminder->created_by))->handle($this, function () use ($reminder, $at) {
                                if (! Access::can('communications.send')) {
                                    $reminder->forceFill(['automation_error' => 'sender_permission_revoked'])->save();

                                    return;
                                }
                                app(Messages::class)->queue(['customer_id' => $reminder->customer_id, 'template_id' => $reminder->template_id, 'scheduled_at' => $at->toIso8601String()], $reminder->created_by, $reminder, 'reminder:'.$reminder->id.':v'.$reminder->version);
                                if ($reminder->automation_error) {
                                    $reminder->forceFill(['automation_error' => null])->save();
                                }
                            });
                        } catch (HttpExceptionInterface|\RuntimeException $e) {
                            app(TenantContext::class)->set($owner);
                            $reminder->forceFill(['automation_error' => 'check_consent_contact_template_or_sender'])->save();
                        } finally {
                            app(TenantContext::class)->set($owner);
                        }
                    }
                    CustomerMessage::where('status', 'sending')->where('lease_until', '<', now())->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'error_message' => 'Submission was interrupted. Reconcile with the provider; automatic resend is disabled.']);
                    foreach (CustomerMessage::whereIn('status', ['accepted', 'sent'])->where('channel', '!=', 'email')->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get() as $m) {
                        if ($status = app(Provider::class)->poll($m)) {
                            app(Messages::class)->providerStatus($m, $status['status'] ?? '', isset($status['code']) ? (string) $status['code'] : null);
                        }
                        $m->touch();
                    }
                    foreach (CustomerMessage::whereIn('status', Messages::PENDING)->where('scheduled_at', '<=', now())->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(100)->get() as $m) {
                        SendCustomerMessage::dispatch($owner->shop_id, $m->created_by, $m->id);
                    }
                });
            } catch (\RuntimeException $e) {
                report($e);
                $this->warn('Shop '.$owner->shop_id.' operations were not processed; inspect application logs.');
            }
        }
        Cache::put('optical:operations-tick', time(), 86400);
        $this->info('Customer operations reconciled.');

        return self::SUCCESS;
    }
}
