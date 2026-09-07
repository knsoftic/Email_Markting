<?php

namespace App\Http\Requests\Automation;

use App\Models\Automation;
use App\Services\Smtp\SmtpSelector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The automation itself: what starts it, who it sends as, and whether a
 * contact may enter it twice.
 *
 * ── Why the trigger config is validated per trigger ─────────────────────────
 * `trigger_config` is one JSON column shared by seven triggers, and the fields
 * that matter to one are meaningless to the next. The form only renders — and
 * therefore only submits — the fields belonging to the selected trigger, and
 * triggerConfig() below keeps only those, so switching a live automation from
 * "tag added" to "specific date" cannot leave a stale tag_id behind for
 * AutomationTrigger to match on later.
 *
 * ── Optional ids are a feature, not a missing validation ────────────────────
 * AutomationTrigger::idMatches() treats a missing id as "any of them": no tag
 * means any tag, no campaign means any campaign. Two triggers cannot work that
 * way, because AutomationSweeper has nothing to look at without them —
 * campaign_not_opened needs the campaign that was not opened, and
 * specific_date needs the date. Those two are required here; the rest are not.
 */
class AutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('automation.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = (int) $this->user()->account_id;
        $trigger = $this->triggerType();

        $rules = [
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger_type' => ['required', 'string', Rule::in(Automation::TRIGGERS)],

            'from_name' => ['nullable', 'string', 'max:191'],
            'from_email' => ['nullable', 'email:rfc', 'max:191'],
            // Checked against the accounts this tenant may actually send
            // through, not merely against the table: a global SMTP account
            // exists for everybody but is only usable by the tenants the
            // super admin assigned it to.
            'smtp_account_id' => ['nullable', 'integer', Rule::in($this->sendableSmtpIds())],
            'allow_reentry' => ['boolean'],
        ];

        return array_merge($rules, $this->triggerRules($trigger, $accountId));
    }

    /**
     * @return array<string, mixed>
     */
    protected function triggerRules(string $trigger, int $accountId): array
    {
        $tag = Rule::exists('tags', 'id')->where('account_id', $accountId);
        $list = Rule::exists('subscriber_lists', 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        $campaign = Rule::exists('campaigns', 'id')->where('account_id', $accountId)->whereNull('deleted_at');

        return match ($trigger) {
            'tag_added' => [
                'tag_id' => ['nullable', 'integer', $tag],
            ],

            'list_joined' => [
                'list_id' => ['nullable', 'integer', $list],
            ],

            'subscriber_added' => [
                'list_ids' => ['nullable', 'array'],
                'list_ids.*' => ['integer', $list],
            ],

            'campaign_opened' => [
                'campaign_id' => ['nullable', 'integer', $campaign],
            ],

            'link_clicked' => [
                'campaign_id' => ['nullable', 'integer', $campaign],
                // A link only exists inside a campaign, so it is checked
                // against the campaign that was chosen. Without a campaign
                // there is no link list to pick from and the field is dropped.
                'link_id' => [
                    'nullable', 'integer',
                    Rule::exists('campaign_links', 'id')->where('campaign_id', (int) $this->input('campaign_id')),
                ],
            ],

            'campaign_not_opened' => [
                'campaign_id' => ['required', 'integer', $campaign],
                'after_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            ],

            'specific_date' => [
                'date' => ['required', 'date'],
                'time' => ['required', 'date_format:H:i'],
                'list_ids' => ['nullable', 'array'],
                'list_ids.*' => ['integer', $list],
            ],

            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'campaign_id.required' => 'Choose the campaign. "Did not open" has to name the email that was not opened.',
            'after_hours.required' => 'Say how long to wait before deciding somebody has not opened it.',
            'after_hours.max' => 'The window is measured in hours and cannot be longer than a year (8760).',
            'date.required' => 'Choose the date this automation should start people on.',
            'time.required' => 'Choose a time of day, in 24-hour HH:MM form.',
            'time.date_format' => 'Enter the time as HH:MM, for example 09:30.',
            'tag_id.exists' => 'That tag is not one of yours.',
            'list_id.exists' => 'That list is not one of yours.',
            'list_ids.*.exists' => 'One of the selected lists is not available.',
            'campaign_id.exists' => 'That campaign is not one of yours.',
            'link_id.exists' => 'That link is not part of the selected campaign.',
            'smtp_account_id.in' => 'That SMTP account is not one this account can send through.',
        ];
    }

    /**
     * The automation's own columns.
     *
     * @return array<string, mixed>
     */
    public function persistable(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_type' => $data['trigger_type'],
            'trigger_config' => $this->triggerConfig(),
            'from_name' => $data['from_name'] ?? null,
            'from_email' => $data['from_email'] ?? null,
            'smtp_account_id' => $data['smtp_account_id'] ?? null,
            // Written out rather than taken from validated(): an unchecked box
            // posts nothing at all, so a re-entry setting the user has just
            // turned OFF would arrive as a missing key and update() would keep
            // the old value on.
            'allow_reentry' => $this->boolean('allow_reentry'),
        ];
    }

    /**
     * Only the config keys that belong to the chosen trigger.
     *
     * @return array<string, mixed>
     */
    public function triggerConfig(): array
    {
        $data = $this->validated();
        $id = fn (string $key) => ($v = (int) ($data[$key] ?? 0)) > 0 ? $v : null;

        $ids = fn (string $key) => array_values(array_filter(array_map(
            'intval',
            is_array($data[$key] ?? null) ? $data[$key] : []
        )));

        return array_filter(match ($this->triggerType()) {
            'tag_added' => ['tag_id' => $id('tag_id')],
            'list_joined' => ['list_id' => $id('list_id')],
            'subscriber_added' => ['list_ids' => $ids('list_ids')],
            'campaign_opened' => ['campaign_id' => $id('campaign_id')],
            'link_clicked' => ['campaign_id' => $id('campaign_id'), 'link_id' => $id('link_id')],
            'campaign_not_opened' => [
                'campaign_id' => $id('campaign_id'),
                'after_hours' => max(1, (int) ($data['after_hours'] ?? 48)),
            ],
            'specific_date' => [
                'date' => (string) ($data['date'] ?? ''),
                'time' => (string) ($data['time'] ?? '00:00'),
                'list_ids' => $ids('list_ids'),
            ],
            default => [],
        }, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** The chosen trigger, or '' when the input is not something it could be. */
    public function triggerType(): string
    {
        $value = $this->input('trigger_type');

        return is_scalar($value) && in_array((string) $value, Automation::TRIGGERS, true)
            ? (string) $value
            : '';
    }

    protected function prepareForValidation(): void
    {
        // Runs BEFORE validation, so it sees raw input — including
        // from_email[]=x, which a plain (string) cast would turn into a fatal
        // before the email rule ever got the chance to reject it.
        $this->merge([
            'from_email' => $this->scalar('from_email') !== '' ? mb_strtolower($this->scalar('from_email')) : null,
            'from_name' => $this->scalar('from_name') ?: null,
            'allow_reentry' => $this->boolean('allow_reentry'),
        ]);

        // A single-select posted as an array, or a multi-select posted as a
        // scalar, are both shapes the validator cannot describe usefully.
        foreach (['tag_id', 'list_id', 'campaign_id', 'link_id', 'smtp_account_id', 'after_hours'] as $key) {
            if ($this->has($key) && ! is_scalar($this->input($key))) {
                $this->merge([$key => null]);
            }
        }

        if ($this->has('list_ids') && ! is_array($this->input('list_ids'))) {
            $this->merge(['list_ids' => []]);
        }
    }

    /**
     * Ids of the SMTP accounts this tenant may send through.
     *
     * @return array<int, int>
     */
    protected function sendableSmtpIds(): array
    {
        $account = $this->user()?->account;

        if ($account === null) {
            return [];
        }

        return app(SmtpSelector::class)
            ->candidatesFor($account)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Raw input as a trimmed string, or '' when it is not a scalar. */
    protected function scalar(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
