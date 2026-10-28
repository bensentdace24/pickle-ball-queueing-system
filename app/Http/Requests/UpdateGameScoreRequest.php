<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGameScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'side' => ['required', 'integer', 'in:0,1'],
            'change' => ['required', 'integer', 'in:-1,1'],
        ];
    }
}
