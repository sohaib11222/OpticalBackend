<?php

namespace App\Services\Commerce;

use App\Models\Shop;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ShopLock
{
    public static function acquire(): Shop
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Shop locking requires a transaction.');
        }
        $id = app(TenantContext::class)->membership()->shop_id;
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no row locks. Acquire its write lock before any transactional read,
            // avoiding a deferred read-to-write upgrade race (including PHP before 8.4).
            DB::table('shops')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return Shop::whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
