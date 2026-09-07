<?php

namespace App\Http\Requests\Contacts;

use App\Models\CustomField;
use App\Models\Subscriber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('subscriber') ? 'contacts.update' : 'contacts.create'
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subscriber = $this->route('subscriber');

        $rules = [
            // Email is unique per account, not globally: two tenants may each
            // hold the same address.
            'email' => [
                'required', 'email:rfc', 'max:191',
                Rule::unique('subscribers', 'email')
                    ->where('account_id', $this->user()->account_id)
                    ->whereNull('deleted_at')
                    ->ignore($subscriber?->id),
            ],
            'name' => ['nullable', 'string', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone'],
            'status' => ['required', Rule::in(Subscriber::STATUSES)],
            'source' => ['nullable', 'string', 'max:100'],
            'consent_status' => ['required', Rule::in(['explicit', 'implied', 'unknown'])],
            'notes' => ['nullable', 'string', 'max:2000'],

            'list_ids' => ['array'],
            'list_ids.*' => [Rule::exists('subscriber_lists', 'id')->where('account_id', $this->user()->account_id)],
            'tag_ids' => ['array'],
            'tag_ids.*' => [Rule::exists('tags', 'id')->where('account_id', $this->user()->account_id)],

            'custom' => ['array'],
        ];

        // Custom fields are account-defined, so their validation is built from
        // the account's own field definitions rather than hard-coded.
        foreach ($this->accountCustomFields() as $field) {
            $key = "custom.{$field->key}";
            $rule = [$field->is_required ? 'required' : 'nullable'];

            $rule[] = match ($field->type) {
                'number' => 'numeric',
                'date' => 'date',
                'boolean' => 'boolean',
                'url' => 'url',
                'select' => Rule::in($field->options ?? []),
                default => 'string',
            };

            if (in_array($field->type, ['text', 'url'], true)) {
                $rule[] = 'max:500';
            }

            $rules[$key] = $rule;
        }

        return $rules;
    }

    public function attributes(): array
    {
        $labels = [];

        foreach ($this->accountCustomFields() as $field) {
            $labels["custom.{$field->key}"] = $field->name;
        }

        return $labels;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'status' => $this->input('status') ?: 'active',
            'consent_status' => $this->input('consent_status') ?: 'unknown',
        ]);
    }

    /**
     * Only the keys the account actually defined survive, so a hand-crafted
     * POST cannot stuff arbitrary data into the custom JSON column.
     *
     * @return array<string, mixed>
     */
    public function customValues(): array
    {
        $allowed = $this->accountCustomFields()->pluck('key')->all();
        $posted = (array) ($this->validated()['custom'] ?? []);

        return array_intersect_key($posted, array_flip($allowed));
    }

    /**
     * Per-INSTANCE memoisation, deliberately not a method-static.
     *
     * A `static` inside this method is shared for the whole PHP process, so
     * under a long-lived runtime (Octane, a queue worker, the test suite) the
     * first request's field list would be reused by every later request — an
     * account with custom fields would silently get the previous account's,
     * or an empty, catalogue. Same class of leak as the tenant one.
     *
     * @var \Illuminate\Support\Collection<int, CustomField>|null
     */
    protected $customFieldCache = null;

    /**
     * @return \Illuminate\Support\Collection<int, CustomField>
     */
    protected function accountCustomFields()
    {
        return $this->customFieldCache ??= CustomField::orderBy('sort_order')->get();
    }
}
