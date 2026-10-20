<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Services\Audit;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function overview(Request $r)
    {
        abort_unless($r->user()->platformCan('platform.view'), 403);

        return response()->json(['data' => ['shops' => Shop::count(), 'pending' => Shop::where('status', 'pending')->count(), 'active' => Shop::where('status', 'active')->count(), 'suspended' => Shop::where('status', 'suspended')->count()]]);
    }

    public function shops(Request $r)
    {
        abort_unless($r->user()->platformCan('shops.view'), 403);
        $d = $r->validate(['search' => 'nullable|string|max:150', 'status' => 'nullable|in:pending,active,suspended', 'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1']);

        return Shop::select([...Shop::PROFILE_FIELDS, 'created_at'])->with(['memberships' => fn ($q) => $q->whereHas('role', fn ($role) => $role->where('name', 'owner'))->select('id', 'shop_id', 'user_id')->with('user:id,name,email,email_verified_at')])->when($d['search'] ?? null, fn ($q, $v) => $q->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%']))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate($d['per_page'] ?? 20);
    }

    public function status(Request $r, Shop $shop)
    {
        abort_unless($r->user()->platformCan('shops.status'), 403);
        $d = $r->validate(['status' => 'required|in:active,suspended', 'expected_status' => 'required|in:pending,active,suspended']);
        DB::transaction(function () use ($r, $shop, $d) {
            $locked = Shop::lockForUpdate()->findOrFail($shop->id);
            abort_unless($locked->status === $d['expected_status'], 409, 'The shop status changed. Refresh and try again.');
            $locked->status = $d['status'];
            $locked->save();
            if ($d['status'] === 'suspended') {
                foreach (User::whereHas('memberships', fn ($q) => $q->where('shop_id', $shop->id))->get() as $u) {
                    Sessions::revoke($u);
                }
            }
            Audit::record('shop.'.$d['status'], $shop->id, $r->user()->id, 'shop', $shop->id);
        });

        return response()->json(['data' => $shop->fresh()->only(['id', 'name', 'status'])]);
    }
}
