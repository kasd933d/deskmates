<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->is_suspended;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:24'],
            'avatar_emoji' => ['sometimes', 'string', 'max:8', 'in:'.implode(',', config('deskmates.avatar_emojis'))],
            'avatar_color' => ['sometimes', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'show_on_leaderboard' => ['sometimes', 'boolean'],
        ];
    }
}
