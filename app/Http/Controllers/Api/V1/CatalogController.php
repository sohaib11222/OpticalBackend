<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Audit;
use App\Services\Commerce\Catalog;
use App\Services\Commerce\Inventory;
use App\Services\Commerce\ShopLock;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private function permit(string $permission): void
    {
        abort_unless(app(TenantContext::class)->membership()->can($permission), 403);
    }

    public function categories(Request $r)
    {
        $this->permit('products.read');
        $d = $r->validate(['status' => 'nullable|in:active,archived', 'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);

        return ProductCategory::when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['search'] ?? null, fn ($q, $v) => $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($v)).'%']))->orderBy('name')->paginate(50);
    }

    public function categorySave(Request $r, ?int $id = null)
    {
        $this->permit('products.write');
        $d = $r->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:2000', 'status' => 'required|in:active,archived', 'version' => $id ? 'required|integer|min:1' : 'prohibited', 'shop_id' => 'prohibited']);

        return DB::transaction(function () use ($r, $id, $d) {
            ShopLock::acquire();
            $c = $id ? ProductCategory::lockForUpdate()->findOrFail($id) : new ProductCategory;
            if ($id) {
                abort_unless($c->version === (int) $d['version'], 409, 'Category changed. Refresh before saving.');
            }
            $key = mb_strtolower(trim($d['name']));
            if (ProductCategory::where('name_key', $key)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This category name already exists.']);
            }
            $c->fill(['name' => $d['name'], 'name_key' => $key, 'description' => $d['description'] ?? null, 'status' => $d['status']]);
            if ($id) {
                $c->version++;
            }$c->save();
            Audit::record('category.saved', $c->shop_id, $r->user()->id, 'product_category', $c->id);

            return response()->json(['data' => $c->refresh()], $id ? 200 : 201);
        });
    }

    public function index(Request $r)
    {
        $this->permit('products.read');
        $d = $r->validate(['search' => 'nullable|string|max:150', 'category_id' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,active,archived', 'stock' => 'nullable|in:low,available,out', 'page' => 'nullable|integer|min:1', 'sort' => 'nullable|in:name,newest,price_asc,price_desc', 'per_page' => 'nullable|integer|min:1|max:100', 'family_id' => 'nullable|integer|min:1']);
        if (! empty($d['category_id'])) {
            ProductCategory::findOrFail($d['category_id']);
        }
        $q = Product::with(['category', 'balance', 'primaryImage', 'parentProduct:id,name,sku'])->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v));
        if (! empty($d['family_id'])) {
            $base = Product::findOrFail($d['family_id']);
            $family = $base->parent_product_id ?? $base->id;
            $q->where(fn ($q) => $q->where('id', $family)->orWhere('parent_product_id', $family));
        }
        if (! empty($d['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($d['search'])).'%';
            $q->where(function ($q) use ($term) {
                foreach (['name', 'sku', 'brand', 'model', 'color'] as $f) {
                    $q->orWhereRaw("LOWER($f) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        if (! empty($d['stock'])) {
            $q->whereHas('balance', function ($q) use ($d) {
                match ($d['stock']) {
                    'low' => $q->whereRaw('on_hand - reserved <= products.low_stock_threshold'),'available' => $q->whereRaw('on_hand > reserved'),'out' => $q->whereRaw('on_hand = reserved')
                };
            });
        }
        match ($d['sort'] ?? 'name') {
            'name' => $q->orderBy('name'),'newest' => $q->orderByDesc('id'),'price_asc' => $q->orderBy('price_minor'),'price_desc' => $q->orderByDesc('price_minor')
        };

        return $q->orderBy('id')->paginate($d['per_page'] ?? 20);
    }

    public function show(int $id)
    {
        $this->permit('products.read');

        return ['data' => Product::with(['category', 'balance', 'images', 'primaryImage', 'parentProduct:id,name,sku'])->findOrFail($id)];
    }

    private function fields(Request $r, bool $editing = false): array
    {
        return $r->validate(['name' => 'required|string|max:200', 'sku' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'brand' => 'required|string|max:100', 'model' => 'required|string|max:100', 'color' => 'required|string|max:80', 'description' => 'nullable|string|max:10000', 'category_id' => 'required|integer|min:1', 'price' => 'required|string|max:24', 'low_stock_threshold' => 'required|integer|min:0|max:1000000', 'initial_quantity' => $editing ? 'prohibited' : 'required|integer|min:0|max:1000000', 'version' => $editing ? 'required|integer|min:1' : 'prohibited', 'creation_key' => $editing ? 'prohibited' : 'required|uuid', 'parent_product_id' => $editing ? 'prohibited' : 'nullable|integer|min:1', 'variation_attributes' => 'nullable|array:size,material,lens_width,bridge_width,temple_length,base_curve,diameter,power', 'variation_attributes.*' => 'nullable|string|max:100', 'status' => 'prohibited', 'shop_id' => 'prohibited', 'on_hand' => 'prohibited', 'reserved' => 'prohibited', 'currency' => 'prohibited', 'price_minor' => 'prohibited']);
    }

    public function store(Request $r, Catalog $service)
    {
        $this->permit('products.write');
        $d = $this->fields($r);
        if ($d['initial_quantity'] > 0) {
            $this->permit('stock.write');
            $this->permit('stock.create');
        }[$p,$created] = $service->save($d, $r->user()->id);

        return response()->json(['data' => $p], $created ? 201 : 200);
    }

    public function update(Request $r, Catalog $service, int $id)
    {
        $this->permit('products.write');
        [$p] = $service->save($this->fields($r, true), $r->user()->id, $id);

        return ['data' => $p];
    }

    public function status(Request $r, Catalog $service, int $id)
    {
        $this->permit('products.write');

        return ['data' => $service->status($id, $r->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:draft,active,archived']), $r->user()->id)];
    }

    public function inventory(Request $r)
    {
        $this->permit('stock.read');
        $d = $r->validate(['product_id' => 'nullable|integer|min:1', 'low' => 'nullable|boolean', 'search' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1']);
        if (! empty($d['product_id'])) {
            Product::findOrFail($d['product_id']);
        }

        return StockBalance::with('product.category')->when($d['search'] ?? null, function ($q, $v) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($v)).'%';
            $q->whereHas('product', fn ($p) => $p->where(fn ($p) => $p->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$term])->orWhereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$term])));
        })->when($d['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))->when($d['low'] ?? false, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('status', 'active')->whereRaw('stock_balances.on_hand - stock_balances.reserved <= products.low_stock_threshold')))->orderBy('product_id')->paginate(30);
    }

    public function movements(Request $r, int $id)
    {
        $this->permit('stock.read');
        Product::findOrFail($id);
        $d = $r->validate(['page' => 'nullable|integer|min:1', 'kind' => 'nullable|in:receipt,adjustment,adjustment_reversal,reservation,release,fulfilment,sale_reversal']);

        return StockMovement::where('product_id', $id)->with(['actor:id,name', 'reversal:id,reverses_id'])->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->orderByDesc('id')->paginate(30);
    }

    public function move(Request $r, Inventory $service, int $id)
    {
        $this->permit('stock.write');
        $d = $r->validate(['kind' => 'required|in:receipt,adjustment', 'quantity' => 'required|integer|between:-1000000,1000000', 'reason' => 'required|string|min:3|max:2000', 'version' => 'required|integer|min:1', 'creation_key' => 'required|uuid', 'shop_id' => 'prohibited']);
        $d['quantity'] = (int) $d['quantity'];
        $d['version'] = (int) $d['version'];

        return response()->json(['data' => $service->manual($id, $d, $r->user()->id)], 201);
    }

    public function reverse(Request $r, Inventory $service, int $id)
    {
        $this->permit('stock.write');

        return response()->json(['data' => $service->reverse($id, $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000', 'creation_key' => 'required|uuid']), $r->user()->id)], 201);
    }
}
