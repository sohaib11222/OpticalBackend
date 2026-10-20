<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Catalog
{
    public function save(array $d, int $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($d, $actor, $id) {
            $shop = ShopLock::acquire();
            abort_unless($shop->currency, 409, 'Set the shop currency in shop settings before creating products.');
            $category = ProductCategory::findOrFail($d['category_id']);
            $fields = array_intersect_key($d, array_flip(['name', 'sku', 'brand', 'model', 'color', 'description', 'category_id', 'low_stock_threshold']));
            $fields['sku'] = strtoupper(trim($fields['sku'] ?? ''));
            $fields['price_minor'] = Money::parse($d['price'], $shop->currency);
            $fields['currency'] = $shop->currency;
            $fields['price_decimals'] = Money::precision($shop->currency);
            // Hash the requested SKU (including automatic mode), never a newly generated value.
            $identity = array_intersect_key($d, array_flip(['parent_product_id', 'variation_attributes']));
            $hash = hash('sha256', json_encode([$fields, (int) ($d['initial_quantity'] ?? 0), ...($identity ? [$identity] : [])], JSON_THROW_ON_ERROR));
            if (! $id && ($previous = Product::where('creation_key', $d['creation_key'])->first())) {
                abort_unless(hash_equals($previous->creation_hash, $hash), 409, 'This product request key was already used with different details.');

                return [$previous->load(['category', 'balance', 'primaryImage']), false];
            }
            $p = $id ? Product::lockForUpdate()->findOrFail($id) : new Product;
            if ($id) {
                abort_unless($p->version === (int) $d['version'], 409, 'Product changed. Refresh before saving.');
                abort_unless($p->status !== 'archived', 409, 'Restore this product before editing.');
            }
            if (! $fields['sku']) {
                $fields['sku'] = $id ? $p->sku : $this->nextSku();
            }
            $parentId = $id ? $p->parent_product_id : ($d['parent_product_id'] ?? null);
            $attributes = $d['variation_attributes'] ?? $p->variation_attributes ?? [];
            $attributes = array_map(fn ($v) => trim($v ?? ''), $attributes);
            $attributes = array_filter($attributes, fn ($v) => $v !== '');
            ksort($attributes);
            if ($parentId) {
                $parent = Product::findOrFail($parentId);
                abort_if($parent->parent_product_id, 422, 'A variation must belong to a base product.');
                abort_if($parent->status === 'archived', 409, 'Restore the base product before managing its variations.');
                abort_unless($parent->category_id === (int) $d['category_id'], 422, 'A variation must use its base product category.');
                $key = hash('sha256', json_encode([mb_strtolower(trim($fields['color'])), array_map('mb_strtolower', $attributes)], JSON_THROW_ON_ERROR));
                if (Product::where('parent_product_id', $parentId)->where('variation_key', $key)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['variation_attributes' => 'This color and attribute combination already exists. Edit or restore the existing variation.']);
                }
                $p->parent_product_id = $parentId;
                $p->variation_key = $key;
            } elseif ($id && $p->category_id !== (int) $d['category_id'] && $p->variations()->exists()) {
                throw ValidationException::withMessages(['category_id' => 'A product family must keep the same category.']);
            }
            $p->variation_attributes = $attributes;
            abort_unless($category->status === 'active' || ($id && $p->category_id === $category->id), 422, 'Select an active category.');
            if (Product::where('sku', $fields['sku'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['sku' => 'This SKU already exists in your catalog.']);
            }
            $p->fill($fields);
            if ($p->status === 'active') {
                abort_unless(trim($p->description ?? '') !== '', 422, 'Active products require a description.');
            }
            if ($id) {
                $p->version++;
            } else {
                $p->creation_key = $d['creation_key'];
                $p->creation_hash = $hash;
            }
            $p->save();
            $p->refresh();
            $stock = app(Inventory::class);
            $stock->balance($p);
            if (! $id && ($d['initial_quantity'] ?? 0) > 0) {
                $stock->move($p, 'receipt', (int) $d['initial_quantity'], 0, 'product:'.$p->id.':opening', 'Opening stock', $actor, 'product', $p->id);
            }
            $stock->lowStock($p);
            Audit::record($id ? 'product.updated' : 'product.created', $p->shop_id, $actor, 'product', $p->id);

            return [$p->load(['category', 'balance', 'primaryImage']), true];
        }, 3);
    }

    private function nextSku(): string
    {
        // The tenant shop lock serializes generated and manually entered SKUs.
        // The database unique index remains the final protection.
        $number = (int) Product::max('id') + 1;
        do {
            $sku = 'OPT-'.str_pad((string) $number++, 6, '0', STR_PAD_LEFT);
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    public function status(int $id, array $d, int $actor): Product
    {
        return DB::transaction(function () use ($id, $d, $actor) {
            ShopLock::acquire();
            $p = Product::lockForUpdate()->findOrFail($id);
            abort_unless($p->version === (int) $d['version'], 409, 'Product changed. Refresh before changing its status.');
            if ($d['status'] === 'active') {
                abort_if($p->parent_product_id && $p->parentProduct()->where('status', 'archived')->exists(), 409, 'Restore the base product first.');
                abort_unless(trim($p->description ?? '') !== '', 422, 'Add a product description before activating.');
                abort_unless($p->primary_image_id && ProductImage::where('product_id', $p->id)->where('scan_status', 'clean')->whereNull('archived_at')->whereKey($p->primary_image_id)->exists(), 422, 'Upload a clean product photo and select it as the primary image before activating.');
            }
            $b = $p->balance()->firstOrFail();
            abort_if($d['status'] === 'archived' && $p->variations()->where('status', '!=', 'archived')->exists(), 409, 'Archive the product variations individually before archiving their base product.');
            abort_if($d['status'] === 'archived' && $b->reserved > 0, 409, 'Resolve reserved orders before archiving this product.');
            $p->status = $d['status'];
            $p->version++;
            $p->save();
            app(Inventory::class)->lowStock($p, $b);
            Audit::record('product.'.$p->status, $p->shop_id, $actor, 'product', $p->id);

            return $p->load(['category', 'balance', 'primaryImage']);
        });
    }
}
