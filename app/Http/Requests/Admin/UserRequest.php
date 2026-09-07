<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $creating = $user === null;

        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'designation' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['active', 'suspended', 'pending'])],
            'password' => [$creating ? 'required' : 'nullable', 'confirmed', Password::defaults()],

            // Only used when creating; an existing user's account never moves.
            'company_name' => [Rule::requiredIf($creating && ! $this->boolean('is_super_admin')), 'nullable', 'string', 'max:191'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'is_super_admin' => ['boolean'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'email_verified' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower((string) $this->input('email')),
            'is_super_admin' => $this->boolean('is_super_admin'),
            'email_verified' => $this->boolean('email_verified'),
            'timezone' => $this->input('timezone') ?: 'UTC',
        ]);
    }
}
