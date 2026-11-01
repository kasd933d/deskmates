<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'type' => ['required', Rule::in([Room::TYPE_PUBLIC, Room::TYPE_PRIVATE])],
            'theme' => ['sometimes', 'string', 'max:32'],
            'focus_minutes' => ['required', 'integer', 'min:10', 'max:90'],
            'short_break_minutes' => ['required', 'integer', 'min:3', 'max:30'],
            'long_break_minutes' => ['required', 'integer', 'min:10', 'max:45'],
            'long_break_every' => ['required', 'integer', 'min:2', 'max:6'],
            'max_members' => ['sometimes', 'integer', 'min:2', 'max:'.Room::MAX_MEMBERS[$this->input('type', Room::TYPE_PUBLIC)]],
            'silent_focus' => ['sometimes', 'boolean'],
        ];
    }
}
