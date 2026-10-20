<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopProfileRules;
use App\Models\Shop;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShopController extends Controller
{
    public function show(TenantContext $c, ?string $id = null)
    {
        abort_if($id !== null && (string) $c->id() !== $id, 404);
        $shop = Shop::findOrFail($c->id());
        Gate::authorize('view', $shop);

        return response()->json(['data' => $shop->only(Shop::PROFILE_FIELDS)]);
    }

    public function update(Request $r, TenantContext $c)
    {
        $shop = Shop::findOrFail($c->id());
        Gate::authorize('update', $shop);
        $d = $r->validate([...ShopProfileRules::rules(false), 'name' => 'required|string|max:150', 'status' => 'prohibited', 'shop_id' => 'prohibited']);
        DB::transaction(function () use ($d) {
            $current = ShopLock::acquire();
            $current->fill($d);
            $current->settings_version++;
            $current->save();
        });
        Audit::record('shop.updated', $shop->id, $r->user()->id, 'shop', $shop->id);

        return $this->show($c);
    }

    public function members(TenantContext $c)
    {
        abort_unless($c->membership()->can('members.view'), 403);
        $rows = Shop::findOrFail($c->id())->memberships()->with('user:id,name,email', 'role:id,name')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name, 'status' => $m->status]);

        return response()->json(['data' => $rows]);
    }
}
