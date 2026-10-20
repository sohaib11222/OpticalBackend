<?php

namespace App\Services\Commerce;

use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\ShopNotice;
use App\Tenancy\TenantContext;

class Notices
{
    public function emit(string $group, string $permission, string $key, string $type, string $title, string $url): void
    {
        $shop = Shop::findOrFail(app(TenantContext::class)->id());
        $preferences = $shop->notification_preferences ?? [];
        if (($preferences[$group] ?? true) === false) {
            return;
        }
        $members = ShopMembership::where('shop_id', $shop->id)->where('status', 'active')->with('user')->get();
        foreach ($members as $member) {
            if ($member->user->status !== 'active' || ! $member->user->canUseVerifiedFeatures() || ! $member->can($permission)) {
                continue;
            }
            ShopNotice::firstOrCreate(['user_id' => $member->user_id, 'event_key' => $key], ['type' => $type, 'title' => $title, 'url' => $url]);
        }
    }
}
