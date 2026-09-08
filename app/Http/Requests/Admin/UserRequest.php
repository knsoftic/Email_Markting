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
            /*
             * A bare exists:roles,id accepted ANY role id, including a private
             * role belonging to a different customer — an id typed into the
             * request would have attached one tenant's role to another tenant's
             * user. A role is assignable only if it is a system role (account_id
             * null, which is how the seeded ones are stored) or belongs to the
             * account this user is actually in.
             */
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where(
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereNull('account_id')
                    ->orWhere('account_id', $user?->account_id ?? $this->accountIdForNewUser())
                )
            )],
            'email_verified' => ['boolean'],
        ];
    }

    /**
     * The account a NEW user will land in.
     *
     * Creating from this panel always provisions a fresh account, so there is
     * no account whose private roles could apply yet — only the system roles
     * can. Returning null makes the rule above accept exactly those.
     */
    protected function accountIdForNewUser(): ?int
    {
        return null;
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
