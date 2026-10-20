<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShopNotice;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    private function permit(string $action): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('notices.'.$action), 403);
    }

    public function index(Request $r)
    {
        $this->permit('read');
        $d = $r->validate(['page' => 'nullable|integer|min:1', 'unread' => 'nullable|boolean']);
        $q = $this->visible($r->user()->id);

        return ['notices' => (clone $q)->when($d['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))->orderByDesc('id')->paginate(20), 'unread' => (clone $q)->whereNull('read_at')->count()];
    }

    public function read(Request $r, int $id)
    {
        $this->permit('write');
        $n = $this->visible($r->user()->id)->findOrFail($id);
        if (! $n->read_at) {
            $n->read_at = now();
            $n->save();
        }

        return ['data' => $n];
    }

    public function all(Request $r)
    {
        $this->permit('write');
        $this->visible($r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ['message' => 'Notifications marked as read.'];
    }

    private function visible(int $user)
    {
        $membership = app(TenantContext::class)->membership();

        return ShopNotice::where('user_id', $user)->where(function ($q) use ($membership) {
            $q->whereRaw('1=0');
            foreach (['stock' => 'stock.read', 'order' => 'orders.read', 'import' => 'imports.read', 'appointment' => 'agenda.read'] as $type => $permission) {
                if ($membership->can($permission)) {
                    $q->orWhere('type', 'like', $type.'.%');
                }
            }
        });
    }
}
