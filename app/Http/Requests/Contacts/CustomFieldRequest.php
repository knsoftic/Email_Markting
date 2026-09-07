<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CustomFieldRequest extends FormRequest
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
        $field = $this->route('customField');

        return [
            'name' => ['required', 'string', 'max:100'],
            // The key is what lands in subscribers.custom, so it is locked to a
            // safe identifier shape and unique inside the account.
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields', 'key')
                    ->where('account_id', $this->user()->account_id)
                    ->ignore($field?->id),
            ],
            'type' => ['required', Rule::in(['text', 'number', 'date', 'select', 'boolean', 'url'])],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:100'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key must start with a letter and contain only lowercase letters, numbers and underscores.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $key = $this->input('key') ?: Str::slug((string) $this->input('name'), '_');

        $this->merge([
            'key' => Str::lower(preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key)),
            'is_required' => $this->boolean('is_required'),
            'sort_order' => $this->input('sort_order') ?: 0,
            'options' => array_values(array_filter(
                (array) $this->input('options', []),
                fn ($v) => is_string($v) && trim($v) !== ''
            )),
        ]);
    }
}
