<?php

namespace App\Http\Requests;

class ShopProfileRules
{
    public static function rules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes|required';

        return ['phone' => [...explode('|', $presence), 'string', 'max:40', 'regex:/^\+?[0-9 ()\-.]{7,40}$/', function ($attribute, $value, $fail) {
            $digits = preg_replace('/\D/', '', (string) $value);
            if (strlen($digits) < 7 || strlen($digits) > 15) {
                $fail('Use a valid phone number with 7 to 15 digits.');
            }
        }], 'address_line1' => $presence.'|string|max:255', 'address_line2' => 'nullable|string|max:255', 'city' => $presence.'|string|max:100', 'postal_code' => $presence.'|string|max:32', 'country' => $presence.'|string|min:2|max:100', 'timezone' => $presence.'|timezone:all|max:80'];
    }
}
