<?php

namespace App\Services\Contacts;

use App\Models\Campaign;
use App\Models\CustomField;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The catalogue of things a segment can filter on.
 *
 * Each field owns its own operators and its own contribution to the query, so
 * adding a new filter means adding one descriptor here and nothing else — the
 * compiler never learns about individual fields.
 *
 * Nothing in a rule is ever interpolated into SQL. A field key that is not in
 * this registry, or an operator the descriptor does not declare, is rejected
 * before a query is built.
 */
class SegmentFieldRegistry
{
    /** Operator sets, reused across descriptors of the same shape. */
    public const TEXT_OPS = ['is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_set', 'is_not_set'];

    public const ENUM_OPS = ['is', 'is_not', 'in'];

    public const DATE_OPS = ['before', 'after', 'on', 'in_last_days', 'not_in_last_days', 'is_set', 'is_not_set'];

    public const REL_OPS = ['is', 'is_not'];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $cache = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->cache ??= array_merge($this->coreFields(), $this->customFields());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function supportsOperator(string $field, string $operator): bool
    {
        return in_array($operator, $this->get($field)['operators'] ?? [], true);
    }

    /**
     * Shape the rule-builder UI reads: field key, label, group, input type and
     * the option list where the value is a fixed choice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUi(): array
    {
        return collect($this->all())->map(function (array $field, string $key) {
            // List / tag / campaign option lists are lazy closures so the
            // registry does not query on every descriptor lookup; they are
            // resolved exactly once, here, for the builder payload.
            $options = $field['options'] ?? [];

            return [
                'key' => $key,
                'label' => $field['label'],
                'group' => $field['group'],
                'input' => $field['input'],
                'operators' => array_values($field['operators']),
                'options' => is_callable($options) ? $options() : $options,
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------- core

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function coreFields(): array
    {
        return [
            'email' => $this->text('Email address', 'Contact', 'email'),
            'name' => $this->text('Name', 'Contact', 'name'),
            'company' => $this->text('Company', 'Contact', 'company'),
            'phone' => $this->text('Phone', 'Contact', 'phone'),
            'country' => $this->text('Country', 'Location', 'country'),
            'city' => $this->text('City', 'Location', 'city'),

            'status' => [
                'label' => 'Status',
                'group' => 'Contact',
                'input' => 'select',
                'operators' => self::ENUM_OPS,
                'options' => collect(Subscriber::STATUSES)
                    ->map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)])->all(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyEnum($q, 'subscribers.status', $op, $value),
            ],

            'consent_status' => [
                'label' => 'Consent',
                'group' => 'Contact',
                'input' => 'select',
                'operators' => self::ENUM_OPS,
                'options' => [
                    ['value' => 'explicit', 'label' => 'Explicit'],
                    ['value' => 'implied', 'label' => 'Implied'],
                    ['value' => 'unknown', 'label' => 'Unknown'],
                ],
                'apply' => fn (Builder $q, string $op, $value) => $this->applyEnum($q, 'subscribers.consent_status', $op, $value),
            ],

            'source' => $this->text('Source', 'Contact', 'source'),

            'created_at' => $this->date('Date added', 'Activity', 'created_at'),
            'subscribed_at' => $this->date('Subscription date', 'Activity', 'subscribed_at'),
            'last_activity_at' => $this->date('Last activity', 'Activity', 'last_activity_at'),

            // ---- relations -------------------------------------------------
            'list' => [
                'label' => 'List membership',
                'group' => 'Audience',
                'input' => 'list',
                'operators' => self::REL_OPS,
                'options' => fn () => SubscriberList::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->name])->all(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyExists(
                    $q, $op,
                    fn ($sub) => $sub->selectRaw(1)->from('list_subscriber')
                        ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                        ->where('list_subscriber.subscriber_list_id', (int) $value)
                ),
            ],

            'tag' => [
                'label' => 'Tag',
                'group' => 'Audience',
                'input' => 'tag',
                'operators' => self::REL_OPS,
                'options' => fn () => Tag::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])->all(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyExists(
                    $q, $op,
                    fn ($sub) => $sub->selectRaw(1)->from('subscriber_tag')
                        ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                        ->where('subscriber_tag.tag_id', (int) $value)
                ),
            ],

            'campaign_received' => [
                'label' => 'Received campaign',
                'group' => 'Engagement',
                'input' => 'campaign',
                'operators' => self::REL_OPS,
                'options' => fn () => $this->campaignOptions(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyExists(
                    $q, $op, $this->recipientSubquery($value, null)
                ),
            ],

            'campaign_opened' => [
                'label' => 'Opened campaign',
                'group' => 'Engagement',
                'input' => 'campaign',
                'operators' => self::REL_OPS,
                'options' => fn () => $this->campaignOptions(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyExists(
                    $q, $op, $this->recipientSubquery($value, 'open_count')
                ),
            ],

            'campaign_clicked' => [
                'label' => 'Clicked a link in campaign',
                'group' => 'Engagement',
                'input' => 'campaign',
                'operators' => self::REL_OPS,
                'options' => fn () => $this->campaignOptions(),
                'apply' => fn (Builder $q, string $op, $value) => $this->applyExists(
                    $q, $op, $this->recipientSubquery($value, 'click_count')
                ),
            ],

            'suppressed' => [
                'label' => 'On the suppression list',
                'group' => 'Compliance',
                'input' => 'boolean',
                'operators' => ['is'],
                'options' => [
                    ['value' => '1', 'label' => 'Yes'],
                    ['value' => '0', 'label' => 'No'],
                ],
                'apply' => function (Builder $q, string $op, $value) {
                    $sub = fn ($s) => $s->selectRaw(1)->from('suppressions')
                        ->whereColumn('suppressions.email', 'subscribers.email')
                        ->whereColumn('suppressions.account_id', 'subscribers.account_id');

                    ((string) $value === '1') ? $q->whereExists($sub) : $q->whereNotExists($sub);
                },
            ],
        ];
    }

    /**
     * Account-defined fields, stored inside the subscribers.custom JSON column.
     *
     * MariaDB 10.4 has no `->` operator, but Laravel's grammar compiles
     * `custom->key` to json_unquote(json_extract(...)), which it does support.
     * That is verified against this deployment, and is why these are safe.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function customFields(): array
    {
        $fields = [];

        foreach (CustomField::orderBy('sort_order')->get() as $field) {
            $path = 'custom->'.$field->key;

            $fields['custom:'.$field->key] = [
                'label' => $field->name,
                'group' => 'Custom fields',
                'input' => $field->type === 'select' ? 'select' : ($field->type === 'date' ? 'date' : 'text'),
                'operators' => $field->type === 'date' ? self::DATE_OPS : self::TEXT_OPS,
                'options' => collect($field->options ?? [])
                    ->map(fn ($o) => ['value' => $o, 'label' => $o])->all(),
                'apply' => fn (Builder $q, string $op, $value) => $field->type === 'date'
                    ? $this->applyDate($q, $path, $op, $value)
                    : $this->applyText($q, $path, $op, $value),
            ];
        }

        return $fields;
    }

    // -------------------------------------------------------- descriptors

    /**
     * @return array<string, mixed>
     */
    protected function text(string $label, string $group, string $column): array
    {
        return [
            'label' => $label,
            'group' => $group,
            'input' => 'text',
            'operators' => self::TEXT_OPS,
            'apply' => fn (Builder $q, string $op, $value) => $this->applyText($q, 'subscribers.'.$column, $op, $value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function date(string $label, string $group, string $column): array
    {
        return [
            'label' => $label,
            'group' => $group,
            'input' => 'date',
            'operators' => self::DATE_OPS,
            'apply' => fn (Builder $q, string $op, $value) => $this->applyDate($q, 'subscribers.'.$column, $op, $value),
        ];
    }

    // ------------------------------------------------------------ appliers

    protected function applyText(Builder $query, string $column, string $operator, mixed $value): void
    {
        $value = is_scalar($value) ? (string) $value : '';

        match ($operator) {
            'is' => $query->where($column, '=', $value),
            'is_not' => $query->where(fn (Builder $q) => $q->where($column, '!=', $value)->orWhereNull($column)),
            'contains' => $query->where($column, 'like', '%'.$this->escapeLike($value).'%'),
            'not_contains' => $query->where(fn (Builder $q) => $q
                ->where($column, 'not like', '%'.$this->escapeLike($value).'%')->orWhereNull($column)),
            'starts_with' => $query->where($column, 'like', $this->escapeLike($value).'%'),
            'ends_with' => $query->where($column, 'like', '%'.$this->escapeLike($value)),
            'is_set' => $query->whereNotNull($column)->where($column, '!=', ''),
            'is_not_set' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '=', '')),
            default => null,
        };
    }

    protected function applyEnum(Builder $query, string $column, string $operator, mixed $value): void
    {
        $values = array_values(array_filter(
            is_array($value) ? $value : [$value],
            fn ($v) => is_scalar($v) && (string) $v !== ''
        ));

        if (empty($values)) {
            return;
        }

        match ($operator) {
            'is' => $query->where($column, '=', (string) $values[0]),
            'is_not' => $query->where($column, '!=', (string) $values[0]),
            'in' => $query->whereIn($column, $values),
            default => null,
        };
    }

    protected function applyDate(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'before' => $query->where($column, '<', $this->toDate($value)),
            'after' => $query->where($column, '>', $this->toDate($value)?->endOfDay()),
            'on' => $query->whereBetween($column, [
                $this->toDate($value)?->startOfDay(),
                $this->toDate($value)?->endOfDay(),
            ]),
            'in_last_days' => $query->where($column, '>=', now()->subDays(max(0, (int) $value))->startOfDay()),
            'not_in_last_days' => $query->where(fn (Builder $q) => $q
                ->where($column, '<', now()->subDays(max(0, (int) $value))->startOfDay())
                ->orWhereNull($column)),
            'is_set' => $query->whereNotNull($column),
            'is_not_set' => $query->whereNull($column),
            default => null,
        };
    }

    /**
     * Negative engagement conditions are NOT EXISTS, never NOT IN over a
     * materialised id list — that is what keeps "did not open" usable when a
     * campaign went to 100k people.
     */
    protected function applyExists(Builder $query, string $operator, callable $subquery): void
    {
        $operator === 'is'
            ? $query->whereExists($subquery)
            : $query->whereNotExists($subquery);
    }

    /**
     * A recipient-row subquery, optionally requiring engagement.
     *
     * $value of 'any' (or empty) means any campaign in the account.
     */
    protected function recipientSubquery(mixed $value, ?string $engagementColumn): callable
    {
        $campaignId = ($value === null || $value === '' || $value === 'any') ? null : (int) $value;

        return function ($sub) use ($campaignId, $engagementColumn) {
            $sub->selectRaw(1)
                ->from('campaign_recipients')
                ->whereColumn('campaign_recipients.subscriber_id', 'subscribers.id');

            if ($campaignId !== null) {
                $sub->where('campaign_recipients.campaign_id', $campaignId);
            }

            if ($engagementColumn !== null) {
                $sub->where('campaign_recipients.'.$engagementColumn, '>', 0);
            } else {
                // "Received" means it actually went out, not merely queued.
                $sub->where('campaign_recipients.status', 'sent');
            }
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function campaignOptions(): array
    {
        return collect([['value' => 'any', 'label' => 'Any campaign']])
            ->merge(
                Campaign::orderByDesc('id')->limit(200)->get(['id', 'name'])
                    ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
            )->all();
    }

    protected function toDate(mixed $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Stops a user-supplied % or _ from turning into a wildcard. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
