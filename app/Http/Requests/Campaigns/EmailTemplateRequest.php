<?php

namespace App\Http\Requests\Campaigns;

use App\Services\Campaigns\BlockCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        if (! $this->user()?->hasPermission($template ? 'templates.update' : 'templates.create')) {
            return false;
        }

        // Same reason as CampaignRequest: authorization runs before validation,
        // so a read-only system template is refused as read-only rather than
        // being told its category is missing.
        if ($template) {
            abort_if($template->is_system, 403,
                'System templates are read-only. Duplicate it to make changes.');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],

            // The document is validated structurally here; BlockCatalogue
            // normalises it afterwards, dropping unknown types and coercing
            // every colour to a literal.
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.type' => ['required', 'string', Rule::in(array_keys(app(BlockCatalogue::class)->all()))],
            'blocks.*.id' => ['nullable', 'string', 'max:64'],
            'blocks.*.settings' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'blocks.required' => 'A template needs at least one block.',
            'blocks.*.type.in' => 'That block type is not available.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // The builder posts the document as a JSON string; a plain form post
        // sends arrays. Accept both so the screen works without JS.
        foreach (['blocks', 'settings'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $this->merge([$key => is_array($decoded) ? $decoded : []]);
            }
        }
    }
}
