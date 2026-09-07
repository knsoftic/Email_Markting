<?php

namespace App\Http\Requests\Campaigns;

use App\Services\Campaigns\BlockCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');

        if (! $this->user()?->hasPermission($campaign ? 'campaigns.update' : 'campaigns.create')) {
            return false;
        }

        // Checked here rather than in the controller because authorization runs
        // BEFORE validation: a post to a sending campaign must be refused for
        // what it is, not answered with "the subject field is required".
        if ($campaign) {
            abort_unless($campaign->isEditable(), 403,
                'This campaign is sending or already sent. Duplicate it to make changes.');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = $this->user()->account_id;

        return [
            'name' => ['required', 'string', 'max:191'],
            'subject' => ['required', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:255'],
            'from_name' => ['required', 'string', 'max:191'],
            'from_email' => ['required', 'email:rfc', 'max:191'],
            'reply_to' => ['nullable', 'email:rfc', 'max:191'],

            'email_template_id' => ['nullable', Rule::exists('email_templates', 'id')],
            'smtp_account_id' => ['nullable', 'integer'],

            // Every audience id is checked against THIS account, so a crafted
            // post cannot mail another tenant's list.
            'audience' => ['array'],
            'audience.lists' => ['array'],
            'audience.lists.*' => [Rule::exists('subscriber_lists', 'id')->where('account_id', $accountId)],
            'audience.tags' => ['array'],
            'audience.tags.*' => [Rule::exists('tags', 'id')->where('account_id', $accountId)],
            'audience.segments' => ['array'],
            'audience.segments.*' => [Rule::exists('segments', 'id')->where('account_id', $accountId)],

            'timezone' => ['required', 'timezone'],
            'track_opens' => ['boolean'],
            'track_clicks' => ['boolean'],

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
            'blocks.required' => 'A campaign needs some content before it can be saved.',
            'audience.lists.*.exists' => 'One of the selected lists is not available.',
            'audience.tags.*.exists' => 'One of the selected tags is not available.',
            'audience.segments.*.exists' => 'One of the selected segments is not available.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['blocks', 'settings', 'audience'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $this->merge([$key => is_array($decoded) ? $decoded : []]);
            }
        }

        // Normalisation runs BEFORE validation, so it sees the raw input —
        // including from_email[]=x, which a plain (string) cast would turn
        // into a fatal before the email rule ever gets to reject it.
        $this->merge([
            'from_email' => $this->lowerAddress('from_email'),
            'reply_to' => $this->lowerAddress('reply_to') ?: null,
            'track_opens' => $this->boolean('track_opens'),
            'track_clicks' => $this->boolean('track_clicks'),
        ]);
    }

    /**
     * A trimmed, lower-cased address, or '' when the input is not something an
     * address field could have held. Left in place rather than removed so the
     * validator still reports the field as required/invalid.
     */
    protected function lowerAddress(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
    }
}
