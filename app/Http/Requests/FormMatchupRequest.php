<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FormMatchupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120'],
            'assignments' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if (! in_array(count($value), [2, 4], true)) {
                        $fail('A matchup needs exactly 2 players (singles) or 4 players (doubles).');
                    }
                },
            ],
            'assignments.*.queue_id' => ['required', 'integer', 'distinct', 'exists:queues,id'],
            'assignments.*.side' => ['required', 'integer', 'in:0,1'],
        ];
    }
}
