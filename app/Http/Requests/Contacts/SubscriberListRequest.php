<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriberListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('list') ? 'contacts.update' : 'contacts.create'
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $list = $this->route('list');

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('subscriber_lists', 'name')
                    ->where('account_id', $this->user()->account_id)
                    ->whereNull('deleted_at')
                    ->ignore($list?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:191'],
            'from_email' => ['nullable', 'email:rfc', 'max:191'],
        ];
    }
}
