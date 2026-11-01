<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:24'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'avatar_emoji' => ['sometimes', 'string', 'max:8', 'in:'.implode(',', config('deskmates.avatar_emojis'))],
            'avatar_color' => ['sometimes', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
