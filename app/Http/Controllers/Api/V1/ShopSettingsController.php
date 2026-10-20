<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopProfileRules;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockLocation;
use App\Services\Audit;
use App\Services\Commerce\Money;
use App\Services\Commerce\ShopLock;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Intl\Currencies;

class ShopSettingsController extends Controller
{
    private function permit(string $permission): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($permission), 403);
    }

    public function show()
    {
        $this->permit('shop.view');
        $s = Shop::findOrFail(app(TenantContext::class)->id());

        return ['data' => ['shop' => $s->only([...Shop::PROFILE_FIELDS, 'currency', 'settings_version', 'notification_preferences']), 'stock_location' => StockLocation::first()?->name ?? 'Main stock', 'currency_locked' => Product::exists()], 'currencies' => collect(Currencies::getNames('en'))->except(['XXX', 'XTS'])->map(fn ($name, $code) => ['code' => $code, 'name' => $name, 'decimals' => Currencies::getFractionDigits($code)])->values()];
    }

    public function update(Request $r)
    {
        $this->permit('shop.update');
        $d = $r->validate([...ShopProfileRules::rules(false), 'name' => 'required|string|max:150', 'currency' => 'required|string|size:3', 'stock_location' => 'required|string|max:100', 'settings_version' => 'required|integer|min:1', 'notification_preferences' => 'required|array:stock,orders,imports,agenda', 'notification_preferences.*' => 'boolean', 'shop_id' => 'prohibited', 'status' => 'prohibited']);
        Money::precision($d['currency']);
        DB::transaction(function () use ($d, $r) {
            $s = ShopLock::acquire();
            abort_unless($s->settings_version === (int) $d['settings_version'], 409, 'Shop settings changed. Refresh before saving.');
            abort_if($s->currency && $s->currency !== $d['currency'] && Product::exists(), 409, 'Currency is locked after creating catalog prices. Historical amounts must not be reinterpreted.');
            $s->fill(array_intersect_key($d, array_flip(['name', ...Shop::BUSINESS_FIELDS])));
            $s->currency = $d['currency'];
            $s->notification_preferences = $d['notification_preferences'];
            $s->settings_version++;
            $s->save();
            StockLocation::updateOrCreate([], ['name' => $d['stock_location']]);
            Audit::record('shop.settings_updated', $s->id, $r->user()->id, 'shop', $s->id);
        });

        return $this->show();
    }
}
