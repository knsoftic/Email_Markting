<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('tag') ? 'contacts.update' : 'contacts.create'
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('tags', 'slug')
                    ->where('account_id', $this->user()->account_id)
                    ->ignore($tag?->id),
            ],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['color.regex' => 'Pick a colour in #rrggbb form.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->input('slug') ?: Str::slug((string) $this->input('name')),
            'color' => $this->input('color') ?: '#2563eb',
        ]);
    }
}
