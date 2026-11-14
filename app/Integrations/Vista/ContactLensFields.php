<?php

namespace App\Integrations\Vista;

use Illuminate\Validation\ValidationException;

/** Validate the seller pack relationships before reserving any Optical stock. */
final class ContactLensFields
{
    public static function validate(array $fields, int $quantity, array $categories): void
    {
        if (($fields['product_type'] ?? null) !== 'contact_lens') {
            return;
        }
        $category = collect($categories)->firstWhere('id', $fields['sub_category_id'] ?? null);
        if ($category && collect($categories)->contains('parent_id', $category['id'])) {
            self::invalid('Choose a lens type within the selected contact lens subcategory.');
        }
        $seen = [];
        while ($category && ! isset($seen[$category['id']])) {
            $seen[$category['id']] = true;
            if (str_contains(strtolower($category['slug']), 'spherical') || strtolower($category['name']) === 'spherical') {
                if (! collect($fields['prescription_values'] ?? [])->contains(fn ($v) => in_array($v['field_type'], ['pwr', 'sph']) && trim($v['value']) !== '')) {
                    self::invalid('Spherical contact lenses require at least one PWR value.');
                }
            }
            $category = collect($categories)->firstWhere('id', $category['parent_id'] ?? null);
        }
        if (array_key_exists('prescription_values', $fields) && ! $fields['prescription_values']) {
            self::invalid('Add the product prescription options before publishing.');
        }
        $colored = self::isColoredLens($fields, $categories);
        $variants = collect($fields['variants'] ?? [])->keyBy('option_key');
        $packs = collect($fields['contact_lens_packs'] ?? []);
        if ($packs->pluck('quantity')->unique()->count() !== $packs->count()) {
            self::invalid('Pack sizes must be unique.');
        }
        foreach ($packs as $pack) {
            if (! $colored && ! empty($pack['variant_keys'])) {
                self::invalid('Standard contact lenses do not use color variations. Choose the coloured-lenses category to configure colors.');
            }
            foreach ($pack['variant_keys'] ?? [] as $key) {
                if (! $variants->has($key)) {
                    self::invalid('Choose a color belonging to this product.');
                }
            }
        }
        $seen = [];
        $stock = $fields['contact_lens_colour_stock'] ?? [];
        if (! $colored && $stock) {
            self::invalid('Standard contact lenses use a quantity per pack, not pack/color stock.');
        }
        foreach ($stock as $row) {
            $pack = $packs->firstWhere('quantity', $row['pack_quantity']);
            $identity = $row['pack_quantity'].':'.$row['variant_key'];
            if (! $pack || ! $variants->has($row['variant_key']) || isset($seen[$identity])) {
                self::invalid('Pack/color stock must reference a unique existing pack and color.');
            }
            if (! empty($pack['variant_keys']) && ! in_array($row['variant_key'], $pack['variant_keys'], true)) {
                self::invalid('This color is not available for the selected pack.');
            }
            $seen[$identity] = true;
        }
        if ($stock) {
            if ((int) array_sum(array_column($stock, 'stock_quantity')) !== $quantity) {
                self::invalid('Pack/color allocations must equal the chosen allocation.');
            }
            foreach ($variants as $key => $variant) {
                if ((int) collect($stock)->where('variant_key', $key)->sum('stock_quantity') !== (int) ($variant['stock_quantity'] ?? 0)) {
                    self::invalid('Pack/color allocations must also match each color allocation.');
                }
            }
        }

        // Standard lenses are stocked per pack. This deliberately does not
        // create colour allocations: a daily/monthly lens must be publishable
        // without colours while still providing an exact total for Vista.
        if (! $colored && $packs->contains(fn ($pack) => array_key_exists('stock_quantity', $pack))) {
            foreach ($packs as $pack) {
                if (! isset($pack['stock_quantity']) || (int) $pack['stock_quantity'] < 0) {
                    self::invalid('Enter a valid stock quantity for every pack.');
                }
            }
            if ((int) $packs->sum(fn ($pack) => (int) ($pack['stock_quantity'] ?? 0)) !== $quantity) {
                self::invalid('Pack stock quantities must equal the total allocation.');
            }
        }
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['mapping.contact_lens_packs' => $message]);
    }

    /**
     * Vista's seller application identifies coloured lenses through the
     * category tree. Do not infer it from mapped variants: legacy mappings
     * can contain variation data on a standard lens.
     */
    private static function isColoredLens(array $fields, array $categories): bool
    {
        $category = collect($categories)->firstWhere('id', $fields['sub_category_id'] ?? null);
        $seen = [];
        while ($category && ! isset($seen[$category['id']])) {
            $seen[$category['id']] = true;
            $source = strtolower(($category['slug'] ?? '').' '.($category['name'] ?? ''));
            if (preg_match('/\\bcolou?red\\b/', str_replace(['-', '_'], ' ', $source))) {
                return true;
            }
            $category = collect($categories)->firstWhere('id', $category['parent_id'] ?? null);
        }

        return false;
    }
}
