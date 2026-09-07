<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:191',
                Rule::unique(\App\Models\User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'designation' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower((string) $this->input('email')),
            'timezone' => $this->input('timezone') ?: 'UTC',
        ]);
    }
}
