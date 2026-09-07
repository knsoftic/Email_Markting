<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
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
        $planId = $this->route('plan')?->id;

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($planId)],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'is_public' => ['boolean'],
        ];

        // Limits accept a blank value, which is stored as NULL = unlimited.
        foreach (Plan::LIMIT_KEYS as $key) {
            $rules[$key] = ['nullable', 'integer', 'min:0', 'max:100000000'];
        }

        foreach (Plan::FEATURE_KEYS as $key) {
            $rules[$key] = ['boolean'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'max_contacts' => 'contact limit',
            'max_emails_per_month' => 'monthly email limit',
            'max_smtp_accounts' => 'SMTP account limit',
            'max_mailboxes' => 'mailbox limit',
        ];
    }

    protected function prepareForValidation(): void
    {
        $booleans = [];

        foreach (array_merge(Plan::FEATURE_KEYS, ['is_active', 'is_default', 'is_public']) as $key) {
            $booleans[$key] = $this->boolean($key);
        }

        // Empty limit inputs mean "unlimited", not zero.
        $limits = [];
        foreach (Plan::LIMIT_KEYS as $key) {
            $value = $this->input($key);
            $limits[$key] = ($value === '' || $value === null) ? null : $value;
        }

        $this->merge(array_merge($booleans, $limits, [
            'slug' => $this->input('slug') ?: str($this->input('name'))->slug()->value(),
            'currency' => strtoupper((string) $this->input('currency', 'USD')),
        ]));
    }
}
