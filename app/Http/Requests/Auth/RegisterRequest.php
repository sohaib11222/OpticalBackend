<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ShopProfileRules;
use App\Rules\PasswordByteLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
        return [...ShopProfileRules::rules(), 'name' => 'required|string|max:150', 'shop_name' => 'required|string|max:150', 'email' => 'required|email:rfc|max:254|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), new PasswordByteLength], 'role' => 'prohibited', 'account_type' => 'prohibited', 'shop_id' => 'prohibited', 'status' => 'prohibited', 'email_verified_at' => 'prohibited'];
    }
}
