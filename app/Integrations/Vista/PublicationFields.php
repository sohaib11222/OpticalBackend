<?php

namespace App\Integrations\Vista;

use App\Models\VistaConnection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublicationFields
{
    public function validate(array $mapping, int $quantity, array $legacy = []): array
    {
        $meta = app(VistaClient::class)->send('GET', 'catalog-metadata', [], VistaConnection::firstOrFail())['data'] ?? [];
        abort_unless(in_array(($meta['schema_version'] ?? 0), [2, 3], true), 409, 'Deploy the Vista category-form update before publishing.');
        $category = collect($meta['categories'] ?? [])->firstWhere('id', $mapping['category_id'] ?? null);
        if (! $category || ! empty($category['parent_id']) || empty($category['product_type']) || $category['product_type'] !== ($mapping['product_type'] ?? null)) {
            throw ValidationException::withMessages(['mapping.category_id' => 'Select a compatible Vista category.']);
        }
        $rules = ['category_id' => 'required|integer', 'product_type' => 'required|string', 'sub_category_id' => 'nullable|integer'];
        $this->rules([...($meta['common_fields'] ?? []), ...($category['fields'] ?? [])], $rules);
        foreach (['frame_shape', 'frame_material'] as $key) {
            if (isset($legacy[$key]) && ($mapping[$key] ?? null) === $legacy[$key] && isset($rules[$key])) $rules[$key] = 'nullable|string|max:255';
        }
        if (isset($mapping['frame_color'], $rules['frame_color'])) {
            $palette = collect($category['fields'])->firstWhere('key', 'frame_color');
            abort_unless(in_array($mapping['frame_color'], $palette['options'] ?? [], true) || preg_match('/^#[0-9A-Fa-f]{6}$/D', $mapping['frame_color']) || ($legacy['frame_color'] ?? null) === $mapping['frame_color'], 422, 'Choose a palette color or a six-digit hex color.');
        }
        $unknown = array_diff(array_keys($mapping), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(['mapping.'.reset($unknown) => 'This field is unavailable for the selected category.']);
        }
        $validated = Validator::make($mapping, $rules)->validate();
        if (! empty($validated['sub_category_id'])) {
            $leaf = collect($meta['categories'])->firstWhere('id', $validated['sub_category_id']);
            $seen = [];
            while ($leaf && ! empty($leaf['parent_id']) && $leaf['parent_id'] !== $category['id'] && ! in_array($leaf['id'], $seen)) {
                $seen[] = $leaf['id'];
                $leaf = collect($meta['categories'])->firstWhere('id', $leaf['parent_id']);
            }
            if (! $leaf || ($leaf['parent_id'] ?? null) !== $category['id']) {
                throw ValidationException::withMessages(['mapping.sub_category_id' => 'Choose a subcategory within the selected category.']);
            }
        }
        $rows = $validated['variants'] ?? $validated['size_volume_variants'] ?? [];
        if ($rows) {
            $total = 0;
            foreach ($rows as $row) {
                $total += ! empty($row['sizes']) ? array_sum(array_column($row['sizes'], 'stock_quantity')) : (int) ($row['stock_quantity'] ?? 0);
            }
            if ($total !== $quantity) {
                throw ValidationException::withMessages(['mapping.variants' => 'Option allocations must equal the chosen total allocation.']);
            }
        }

        ContactLensFields::validate($validated, $quantity, $meta['categories'] ?? []);

        return $validated;
    }

    private function rules(array $fields, array &$rules, string $prefix = ''): void
    {
        foreach ($fields as $f) {
            $key = $prefix.$f['key'];
            $type = $f['type'];
            $rule = ($f['required'] ?? false) ? 'required' : 'nullable';
            $rule .= '|'.match ($type) {
                'rows','prescription','list','multiselect','images','integer-list','variant-multiple' => 'array','number' => ($f['step'] ?? '') === '1' ? 'integer' : 'numeric','boolean' => 'boolean','date' => 'date_format:Y-m-d',default => 'string'
            };
            foreach (['maxLength' => 'max', 'min' => 'min', 'max' => 'max'] as $from => $to) {
                if (isset($f[$from])) {
                    $rule .= '|'.$to.':'.$f[$from];
                }
            }
            if ($type === 'select') {
                $rule .= '|in:'.implode(',', $f['options']);
            }
            if (in_array($type, ['rows', 'prescription'])) {
                $rule .= '|max:'.($f['maxItems'] ?? 50);
                $rules[$key.'.*'] = 'array:'.implode(',', [...array_column($f['fields'], 'key'), 'option_key']);
                $rules[$key.'.*.option_key'] = 'required|uuid|distinct';
                $this->rules($f['fields'], $rules, $key.'.*.');
            }
            if (in_array($type, ['list', 'multiselect', 'images', 'variant-multiple', 'integer-list'])) {
                $rule .= '|max:'.($f['maxItems'] ?? 100);
                $rules[$key.'.*'] = ($type === 'integer-list' ? 'integer|min:1' : 'string|max:100').($type === 'multiselect' ? '|in:'.implode(',', $f['options']) : '');
            }
            if (isset($f['pattern'])) {
                $rule .= '|regex:/^'.$f['pattern'].'$/D';
            }
            $rules[$key] = $rule;
        }
    }
}
