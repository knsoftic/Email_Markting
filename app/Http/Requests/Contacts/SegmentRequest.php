<?php

namespace App\Http\Requests\Contacts;

use App\Services\Contacts\SegmentFieldRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('segment') ? 'contacts.update' : 'contacts.create'
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $segment = $this->route('segment');

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('segments', 'name')
                    ->where('account_id', $this->user()->account_id)
                    ->whereNull('deleted_at')
                    ->ignore($segment?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['all', 'any'])],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.field' => ['required', 'string', 'max:100'],
            'rules.*.operator' => ['required', 'string', 'max:40'],
            'rules.*.value' => ['nullable'],
        ];
    }

    /**
     * Field keys and operators are checked against the live registry rather
     * than a static list, so a rule can never name something the compiler
     * would not recognise — and a rule that references another account's list,
     * tag or campaign is rejected here rather than silently matching nothing.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $registry = app(SegmentFieldRegistry::class);

            foreach ((array) $this->input('rules', []) as $i => $rule) {
                $field = $rule['field'] ?? null;
                $operator = $rule['operator'] ?? null;

                if (! is_string($field) || ! $registry->has($field)) {
                    $validator->errors()->add("rules.{$i}.field", 'That filter is not available.');

                    continue;
                }

                if (! is_string($operator) || ! $registry->supportsOperator($field, $operator)) {
                    $validator->errors()->add("rules.{$i}.operator", 'That condition is not valid for this filter.');

                    continue;
                }

                $this->validateRelationValue($validator, $i, $field, $rule['value'] ?? null);
            }
        });
    }

    protected function validateRelationValue(Validator $validator, int|string $i, string $field, mixed $value): void
    {
        $accountId = $this->user()->account_id;

        $table = match ($field) {
            'list' => 'subscriber_lists',
            'tag' => 'tags',
            'campaign_received', 'campaign_opened', 'campaign_clicked' => 'campaigns',
            default => null,
        };

        if ($table === null) {
            return;
        }

        // "any" is a legitimate value for the campaign filters.
        if ($table === 'campaigns' && ($value === 'any' || $value === null || $value === '')) {
            return;
        }

        $exists = \Illuminate\Support\Facades\DB::table($table)
            ->where('id', (int) $value)
            ->where('account_id', $accountId)
            ->when(in_array($table, ['subscriber_lists', 'campaigns'], true),
                fn ($q) => $q->whereNull('deleted_at'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add("rules.{$i}.value", 'That selection is no longer available.');
        }
    }

    protected function prepareForValidation(): void
    {
        $rules = $this->input('rules');

        // The Alpine builder posts a JSON string; a plain form post sends an
        // array. Accept both so the screen works with and without JS.
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            $this->merge(['rules' => is_array($decoded) ? $decoded : []]);
        }

        $this->merge(['match_type' => $this->input('match_type') ?: 'all']);
    }
}
