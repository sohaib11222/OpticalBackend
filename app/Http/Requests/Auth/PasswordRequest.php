<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordByteLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reset = $this->is('*/reset-password');

        return ['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), new PasswordByteLength], 'current_password' => $reset ? 'prohibited' : 'required|current_password:web', 'email' => $reset ? 'required|email:rfc' : 'prohibited', 'token' => $reset ? 'required|string' : 'prohibited'];
    }
}
