<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        return ['email' => 'required|email:rfc|max:254', 'password' => 'required|string|max:1024', 'role' => 'prohibited', 'shop_id' => 'prohibited'];
    }
}
