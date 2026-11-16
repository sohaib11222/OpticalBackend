<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordByteLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordChangeWithCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), new PasswordByteLength],
            'current_password' => 'prohibited',
            'email' => 'prohibited',
            'token' => 'prohibited',
            'code' => 'prohibited',
        ];
    }
}
