<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class FinishGameRequest extends FormRequest
{
    /** A game ends the moment one team reaches this score. */
    public const WINNING_SCORE = 11;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'team_a_score' => ['required', 'integer', 'min:0', 'max:' . self::WINNING_SCORE],
            'team_b_score' => ['required', 'integer', 'min:0', 'max:' . self::WINNING_SCORE],
            'requeue_player_ids' => ['nullable', 'array'],
            'requeue_player_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Don't pile a second message on top of a missing or non-numeric score
            if ($v->errors()->hasAny(['team_a_score', 'team_b_score'])) {
                return;
            }

            $a = (int) $this->input('team_a_score');
            $b = (int) $this->input('team_b_score');

            // Exactly one team has 11, so there are no draws and no scores like 12-10.
            if (max($a, $b) !== self::WINNING_SCORE || $a === $b) {
                $v->errors()->add(
                    'team_a_score',
                    'A game ends when one team reaches ' . self::WINNING_SCORE . '. The other team must have 10 or fewer.'
                );
            }
        });
    }
}
