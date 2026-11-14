<?php

namespace Tests\Feature;

use App\Integrations\Vista\ContactLensFields;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactLensFieldsTest extends TestCase
{
    public function test_pack_references_and_stock_are_validated_before_publication(): void
    {
        $key = (string) Str::uuid();
        $categories = [
            ['id' => 1, 'parent_id' => null, 'name' => 'Contact lenses', 'slug' => 'contact-lenses'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Coloured lenses', 'slug' => 'coloured-lenses'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'Monthly colours', 'slug' => 'monthly-coloured'],
        ];
        $valid = [
            'product_type' => 'contact_lens',
            'sub_category_id' => 3,
            'variants' => [['option_key' => $key, 'stock_quantity' => 2]],
            'contact_lens_packs' => [['quantity' => 30, 'variant_keys' => [$key]]],
            'contact_lens_colour_stock' => [['pack_quantity' => '30', 'variant_key' => $key, 'stock_quantity' => 2]],
        ];
        ContactLensFields::validate($valid, 2, $categories);
        $this->addToAssertionCount(1);
        foreach ([
            [...$valid, 'contact_lens_packs' => [['quantity' => 30], ['quantity' => 30]]],
            [...$valid, 'contact_lens_packs' => [['quantity' => 30, 'variant_keys' => ['foreign']]]],
            [...$valid, 'contact_lens_colour_stock' => [['pack_quantity' => '90', 'variant_key' => $key, 'stock_quantity' => 2]]],
            [...$valid, 'contact_lens_colour_stock' => array_fill(0, 2, $valid['contact_lens_colour_stock'][0])],
            [...$valid, 'variants' => [['option_key' => $key, 'stock_quantity' => 1]]],
            [...$valid, 'prescription_values' => []],
        ] as $invalid) {
            try {
                ContactLensFields::validate($invalid, 2, $categories);
                $this->fail('An invalid contact-lens mapping was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('mapping.contact_lens_packs', $e->errors());
            }
        }
    }

    public function test_standard_lenses_use_pack_stock_without_color_rows(): void
    {
        $categories = [
            ['id' => 1, 'parent_id' => null, 'name' => 'Contact lenses', 'slug' => 'contact-lenses'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Spherical', 'slug' => 'spherical'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'Monthly', 'slug' => 'monthly'],
        ];
        $fields = [
            'product_type' => 'contact_lens',
            'sub_category_id' => 3,
            'prescription_values' => [['field_type' => 'pwr', 'value' => '-1.50']],
            'contact_lens_packs' => [
                ['quantity' => 30, 'stock_quantity' => 10],
                ['quantity' => 90, 'stock_quantity' => 15],
            ],
        ];
        ContactLensFields::validate($fields, 25, $categories);
        $this->addToAssertionCount(1);

        foreach ([
            [...$fields, 'contact_lens_packs' => [['quantity' => 30, 'stock_quantity' => 10, 'variant_keys' => ['legacy']]]],
            [...$fields, 'contact_lens_packs' => [['quantity' => 30, 'stock_quantity' => 10], ['quantity' => 90, 'stock_quantity' => 14]]],
            [...$fields, 'contact_lens_colour_stock' => [['pack_quantity' => 30, 'variant_key' => 'legacy', 'stock_quantity' => 1]]],
        ] as $invalid) {
            try {
                ContactLensFields::validate($invalid, 25, $categories);
                $this->fail('A standard lens mapping incorrectly required or accepted colour allocation data.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('mapping.contact_lens_packs', $e->errors());
            }
        }
    }

    public function test_spherical_power_and_leaf_category_are_required(): void
    {
        $categories = [
            ['id' => 1, 'parent_id' => null, 'name' => 'Contacts', 'slug' => 'contact-lenses'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Spherical', 'slug' => 'spherical'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'Daily', 'slug' => 'daily'],
        ];
        $fields = ['product_type' => 'contact_lens', 'sub_category_id' => 3, 'prescription_values' => [['field_type' => 'pwr', 'value' => '-1.50']]];
        ContactLensFields::validate($fields, 0, $categories);
        $this->addToAssertionCount(1);
        foreach ([
            [...$fields, 'sub_category_id' => 2],
            [...$fields, 'prescription_values' => [['field_type' => 'diameter', 'value' => '14.20']]],
        ] as $invalid) {
            try {
                ContactLensFields::validate($invalid, 0, $categories);
                $this->fail('Missing lens type/power was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
