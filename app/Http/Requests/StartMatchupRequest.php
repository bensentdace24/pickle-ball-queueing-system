<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartMatchupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'court_id' => ['required', 'integer', 'exists:courts,id'],
        ];
    }
}
