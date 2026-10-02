<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SmartAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'match_size' => ['required', 'integer', 'in:2,4'],
            'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120'],
        ];
    }
}
