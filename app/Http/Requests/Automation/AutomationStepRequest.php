<?php

namespace App\Http\Requests\Automation;

use App\Services\Campaigns\BlockCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One step in an automation.
 *
 * `config` is one JSON column shared by seven step types, and the keys are
 * read back by AutomationRunner::execute(). The rules below are written from
 * that method rather than from the migration's comment — the comment predates
 * the runner and names keys (`field`, `operator`, `on_false`) that nothing
 * executes. What the runner actually reads is:
 *
 *   send_email -> subject, html, plain_text          (or email_template_id)
 *   wait       -> amount, unit
 *   condition  -> check, tag_id|list_id|campaign_id, if_false
 *   add_tag    -> tag_id
 *   remove_tag -> tag_id
 *   move_list  -> list_id (destination), from_list_id (optional)
 *   unsubscribe-> nothing at all
 *
 * Anything else stored under those keys would never run, so config() keeps
 * only the keys belonging to the chosen type.
 */
class AutomationStepRequest extends FormRequest
{
    public const TYPES = [
        'send_email', 'wait', 'condition', 'add_tag', 'remove_tag', 'move_list', 'unsubscribe',
    ];

    public const UNITS = ['minutes', 'hours', 'days', 'weeks'];

    public const CHECKS = ['has_tag', 'on_list', 'opened_campaign', 'clicked_campaign'];

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

        $tag = Rule::exists('tags', 'id')->where('account_id', $accountId);
        $list = Rule::exists('subscriber_lists', 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        $campaign = Rule::exists('campaigns', 'id')->where('account_id', $accountId)->whereNull('deleted_at');

        $rules = [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'label' => ['nullable', 'string', 'max:191'],
        ];

        return array_merge($rules, match ($this->stepType()) {
            'send_email' => [
                'subject' => ['required', 'string', 'max:255'],
                // There is deliberately no email_template_id rule. The builder
                // copies a template's blocks into the step, the way the campaign
                // editor does, so the step is self-contained and an edit to the
                // template months later cannot change what this step sends.
                'blocks' => ['required', 'array', 'min:1'],
                'blocks.*.type' => ['required', 'string', Rule::in(array_keys(app(BlockCatalogue::class)->all()))],
                'blocks.*.id' => ['nullable', 'string', 'max:64'],
                'blocks.*.settings' => ['nullable', 'array'],
                'settings' => ['nullable', 'array'],
            ],

            'wait' => [
                // The upper bound mirrors AutomationStep::waitUntil(), which
                // caps every unit at roughly a year because next_run_at is a
                // TIMESTAMP and cannot hold a date past 2038. Rejecting the
                // number here is better than silently shortening it.
                'amount' => ['required', 'integer', 'min:1', 'max:525600'],
                'unit' => ['required', 'string', Rule::in(self::UNITS)],
            ],

            'condition' => [
                'check' => ['required', 'string', Rule::in(self::CHECKS)],
                'tag_id' => [Rule::requiredIf($this->check() === 'has_tag'), 'nullable', 'integer', $tag],
                'list_id' => [Rule::requiredIf($this->check() === 'on_list'), 'nullable', 'integer', $list],
                'campaign_id' => ['nullable', 'integer', $campaign],
                'if_false' => ['required', 'string', Rule::in(['end', 'skip'])],
            ],

            'add_tag', 'remove_tag' => [
                'tag_id' => ['required', 'integer', $tag],
            ],

            'move_list' => [
                'list_id' => ['required', 'integer', $list],
                'from_list_id' => ['nullable', 'integer', $list],
            ],

            default => [],
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blocks.required' => 'This email has no content yet. Add at least one block before saving the step.',
            'subject.required' => 'An email step needs a subject line.',
            'amount.max' => 'The longest wait this can hold is about a year. Use a shorter wait, or two in a row.',
            'tag_id.required' => 'Choose the tag this step works with.',
            'list_id.required' => 'Choose the list this step works with.',
            'tag_id.exists' => 'That tag is not one of yours.',
            'list_id.exists' => 'That list is not one of yours.',
            'from_list_id.exists' => 'That list is not one of yours.',
            'campaign_id.exists' => 'That campaign is not one of yours.',
        ];
    }

    /**
     * The config for the chosen type, and nothing else. `html` and
     * `plain_text` are compiled by the controller from the block document, so
     * they are deliberately absent here.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $data = $this->validated();
        $id = fn (string $key) => ($v = (int) ($data[$key] ?? 0)) > 0 ? $v : null;

        return match ($this->stepType()) {
            'send_email' => ['subject' => (string) $data['subject']],

            'wait' => [
                'amount' => max(1, (int) $data['amount']),
                'unit' => (string) $data['unit'],
            ],

            'condition' => array_filter([
                'check' => (string) $data['check'],
                'tag_id' => $id('tag_id'),
                'list_id' => $id('list_id'),
                'campaign_id' => $id('campaign_id'),
                'if_false' => (string) $data['if_false'],
            ], fn ($value) => $value !== null),

            'add_tag', 'remove_tag' => ['tag_id' => (int) $data['tag_id']],

            'move_list' => array_filter([
                'list_id' => (int) $data['list_id'],
                'from_list_id' => $id('from_list_id'),
            ], fn ($value) => $value !== null),

            // Nothing to configure: the runner opts the contact out and ends
            // the run there.
            default => [],
        };
    }

    /** The block document posted by the builder, for a send_email step. */
    public function document(): array
    {
        return [
            'settings' => is_array($this->input('settings')) ? $this->input('settings') : [],
            'blocks' => is_array($this->input('blocks')) ? $this->input('blocks') : [],
        ];
    }

    /** The chosen type, or '' when the input is not one. */
    public function stepType(): string
    {
        $value = $this->input('type');

        return is_scalar($value) && in_array((string) $value, self::TYPES, true) ? (string) $value : '';
    }

    /** The chosen condition check, or '' when the input is not one. */
    public function check(): string
    {
        $value = $this->input('check');

        return is_scalar($value) && in_array((string) $value, self::CHECKS, true) ? (string) $value : '';
    }

    protected function prepareForValidation(): void
    {
        // The block builder posts its document as JSON in a hidden field, but
        // nothing forces that shape — a replayed request or a test can post
        // real arrays. Both are accepted, exactly as CampaignRequest does.
        foreach (['blocks', 'settings'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $this->merge([$key => is_array($decoded) ? $decoded : []]);
            }
        }

        // A select posted as an array is a shape the validator cannot describe
        // usefully, and would reach an (int) cast as a fatal.
        foreach (['tag_id', 'list_id', 'from_list_id', 'campaign_id', 'amount'] as $key) {
            if ($this->has($key) && ! is_scalar($this->input($key))) {
                $this->merge([$key => null]);
            }
        }

        $label = $this->input('label');
        $this->merge(['label' => is_scalar($label) && trim((string) $label) !== '' ? trim((string) $label) : null]);
    }
}
