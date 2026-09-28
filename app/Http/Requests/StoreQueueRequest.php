<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required_without:name', 'nullable', 'integer', 'exists:players,id'],
            'name' => ['required_without:player_id', 'nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'skill_level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
        ];
    }
}
