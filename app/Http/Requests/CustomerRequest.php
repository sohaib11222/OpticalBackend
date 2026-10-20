<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return self::fieldRules() + [
            'creation_key' => ($this->isMethod('post') && ! $this->is('*/duplicate-check') ? 'required' : 'sometimes').'|uuid', 'version' => $this->isMethod('patch') ? 'required|integer|min:1' : 'prohibited',
            'confirm_distinct' => 'sometimes|boolean', 'shop_id' => 'prohibited', 'status' => 'prohibited', 'source' => 'prohibited'];
    }

    public static function fieldRules(): array
    {
        return ['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()\-.]{7,40}$/'], 'email' => 'nullable|email:rfc|max:254', 'street_address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:100', 'postal_code' => 'nullable|string|max:32', 'sex' => 'nullable|string|max:40'];
    }
}
