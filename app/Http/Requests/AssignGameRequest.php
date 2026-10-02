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
            'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120'],
            'assignments' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if (! in_array(count($value), [2, 4], true)) {
                        $fail('A game requires exactly 2 players (singles) or 4 players (doubles).');
                    }
                },
            ],
            'assignments.*.queue_id' => ['required', 'integer', 'distinct', 'exists:queues,id'],
            'assignments.*.side' => ['required', 'integer', 'in:0,1'],
        ];
    }
}
