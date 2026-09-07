<?php

namespace App\Http\Requests\Contacts;

use App\Models\Suppression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuppressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('contacts.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Accepts one address or a pasted block; parsed by emails().
            'emails' => ['required', 'string', 'max:100000'],
            'reason' => ['required', Rule::in(Suppression::REASONS)],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Splits the pasted block on commas, semicolons and newlines, keeps only
     * syntactically valid addresses, and reports the rest back to the user.
     *
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    public function emails(): array
    {
        $parts = preg_split('/[\s,;]+/', (string) $this->validated()['emails']) ?: [];

        $valid = [];
        $invalid = [];

        foreach ($parts as $part) {
            $email = mb_strtolower(trim($part));

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $email;
            } else {
                $invalid[] = $email;
            }
        }

        return ['valid' => array_values(array_unique($valid)), 'invalid' => array_values(array_unique($invalid))];
    }
}
