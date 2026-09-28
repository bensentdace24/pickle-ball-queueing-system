<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'queue_ids' => ['required', 'array', 'size:4'],
            'queue_ids.*' => ['integer', 'distinct', 'exists:queues,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'queue_ids.size' => 'A game requires exactly 4 players.',
        ];
    }
}
