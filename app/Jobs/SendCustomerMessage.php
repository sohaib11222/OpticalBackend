<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithTenant;
use App\Models\CustomerMessage;
use App\Services\Commerce\ShopLock;
use App\Services\Communications\DeliveryFailure;
use App\Services\Communications\Messages;
use App\Services\Communications\Provider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class SendCustomerMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $shopId, public int $userId, public int $messageId)
    {
        $this->onQueue('communications');
    }

    public function handle(Messages $messages, Provider $provider): void
    {
        $entered = false;
        try {
            (new WithTenant($this->shopId, $this->userId))->handle($this, function () use ($messages, $provider, &$entered) {
                $entered = true;
                $m = DB::transaction(function () use ($messages, $provider) {
                    ShopLock::acquire();
                    $m = CustomerMessage::lockForUpdate()->findOrFail($this->messageId);
                    if (! in_array($m->status, Messages::PENDING) || $m->scheduled_at->isFuture() || $m->next_attempt_at?->isFuture()) {
                        return null;
                    }
                    if ($reason = $messages->eligible($m)) {
                        $m->forceFill(['status' => 'cancelled', 'error_code' => $reason, 'error_message' => 'Sending is no longer authorized. Review recipient opt-outs and source records.'])->save();
                        $messages->event($m, 'cancelled', $reason);

                        return null;
                    }
                    if (! $provider->ready($m->channel)) {
                        $m->forceFill(['status' => 'blocked', 'error_code' => 'provider_not_ready', 'error_message' => 'Platform messaging is disabled or missing credentials.', 'next_attempt_at' => now()->addMinutes(5)])->save();

                        return null;
                    }
                    if (RateLimiter::tooManyAttempts('communications:platform', max(1, config('communications.per_minute')))) {
                        $m->forceFill(['next_attempt_at' => now()->addMinute()])->save();

                        return null;
                    }
                    RateLimiter::hit('communications:platform', 60);
                    $m->forceFill(['status' => 'sending', 'attempts' => $m->attempts + 1, 'lease_until' => now()->addMinutes(2), 'error_code' => null, 'error_message' => null])->save();

                    return $m;
                }, 3);
                if (! $m) {
                    return;
                }
                try {
                    $result = $provider->send($m);
                } catch (DeliveryFailure $e) {
                    $result = ['status' => $e->state === 'retry_wait' && $m->attempts >= 5 ? 'failed' : $e->state, 'error_code' => $e->reason, 'error_message' => $e->getMessage(), 'next_attempt_at' => $e->state === 'retry_wait' ? now()->addSeconds(min(3600, 60 * 2 ** $m->attempts)) : null];
                } catch (\Throwable $e) {
                    $result = ['status' => 'uncertain', 'error_code' => 'submission_unknown', 'error_message' => 'Submission could not be confirmed. Check the provider before sending again.'];
                }
                DB::transaction(function () use ($m, $result, $messages) {
                    ShopLock::acquire();
                    $current = CustomerMessage::lockForUpdate()->findOrFail($m->id);
                    // A signed callback may arrive before the submit response is persisted.
                    if ($current->status !== 'sending') {
                        if (! $current->provider_id && isset($result['provider_id'])) {
                            $current->forceFill(['provider_id' => $result['provider_id']])->save();
                        }

                        return;
                    }
                    $current->forceFill([...$result, 'lease_until' => null, 'submitted_at' => isset($result['provider_id']) ? now() : null])->save();
                    $messages->event($current, $current->status, $current->error_code);
                });
            });
        } catch (\RuntimeException $e) {
            if ($entered) {
                throw $e;
            }
            CustomerMessage::withoutGlobalScope('shop')->where('shop_id', $this->shopId)->whereKey($this->messageId)->whereIn('status', Messages::PENDING)->update(['status' => 'cancelled', 'error_code' => 'access_revoked', 'error_message' => 'Shop or sender access was revoked.']);
        }
    }
}
