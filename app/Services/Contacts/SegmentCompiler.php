<?php

namespace App\Services\Contacts;

use App\Models\Segment;
use App\Models\Subscriber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a saved rule set into one Eloquent query.
 *
 * The compiler is deliberately ignorant of individual fields: it validates a
 * rule against SegmentFieldRegistry and then hands the query to that field's
 * own descriptor. Anything not in the registry is dropped, so a hand-crafted
 * rules payload cannot reach the SQL layer.
 */
class SegmentCompiler
{
    public function __construct(protected SegmentFieldRegistry $registry) {}

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function compile(array $rules, string $matchType = 'all', ?Builder $base = null): Builder
    {
        $query = $base ?? Subscriber::query();
        $boolean = $matchType === 'any' ? 'or' : 'and';
        $valid = $this->validRules($rules);

        if ($valid->isEmpty()) {
            return $query;
        }

        // Every rule becomes its own nested group. Nesting is what lets an
        // "any" segment OR together conditions that are internally multi-part
        // (is_not on a nullable column, for instance) without the OR leaking
        // out and widening the whole query.
        $query->where(function (Builder $outer) use ($valid, $boolean) {
            foreach ($valid as $rule) {
                $descriptor = $this->registry->get($rule['field']);

                $outer->where(function (Builder $inner) use ($descriptor, $rule) {
                    ($descriptor['apply'])($inner, $rule['operator'], $rule['value']);
                }, null, null, $boolean);
            }
        });

        return $query;
    }

    public function forSegment(Segment $segment, ?Builder $base = null): Builder
    {
        return $this->compile($segment->rules ?? [], $segment->match_type ?? 'all', $base);
    }

    /**
     * Rows a campaign would actually be allowed to send to: the segment,
     * narrowed to mailable contacts.
     */
    public function mailableForSegment(Segment $segment): Builder
    {
        return $this->forSegment($segment, Subscriber::query()->mailable());
    }

    public function count(array $rules, string $matchType = 'all'): int
    {
        return $this->compile($rules, $matchType)->count();
    }

    /**
     * Count plus a small sample, for the live preview in the rule builder.
     *
     * @return array{count: int, sample: Collection<int, Subscriber>, rules_applied: int, rules_ignored: array<int, string>}
     */
    public function preview(array $rules, string $matchType = 'all', int $sampleSize = 10): array
    {
        $valid = $this->validRules($rules);
        $ignored = $this->invalidRuleLabels($rules);

        $query = $this->compile($rules, $matchType);

        return [
            'count' => (clone $query)->count(),
            'sample' => (clone $query)
                ->latest('id')
                ->limit($sampleSize)
                ->get(['id', 'email', 'name', 'status', 'country', 'created_at']),
            'rules_applied' => $valid->count(),
            'rules_ignored' => $ignored,
        ];
    }

    /**
     * Recomputes and stores a segment's cached size. Called after a save and
     * from the recalculate action, so the index can show a number without
     * running every segment's query on page load.
     */
    public function refreshCount(Segment $segment): int
    {
        $count = $this->forSegment($segment)->count();

        $segment->forceFill([
            'cached_count' => $count,
            'last_calculated_at' => now(),
        ])->save();

        return $count;
    }

    /**
     * Human-readable description of a rule set, used on the segment index and
     * in the campaign audience summary.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, string>
     */
    public function describe(array $rules, string $matchType = 'all'): array
    {
        return $this->validRules($rules)->map(function (array $rule) {
            $descriptor = $this->registry->get($rule['field']);
            $value = $rule['value'];

            $options = $descriptor['options'] ?? [];
            if (is_callable($options)) {
                $options = $options();
            }

            $label = collect($options)->firstWhere('value', (string) $value)['label'] ?? $value;

            $operatorLabel = str_replace('_', ' ', $rule['operator']);

            return trim($descriptor['label'].' '.$operatorLabel.' '.(is_scalar($label) ? $label : ''));
        })->values()->all();
    }

    // ---------------------------------------------------------- internals

    /**
     * @param  array<int, mixed>  $rules
     * @return Collection<int, array{field: string, operator: string, value: mixed}>
     */
    protected function validRules(array|Arrayable $rules): Collection
    {
        return collect($rules)
            ->map(fn ($rule) => is_array($rule) ? $rule : null)
            ->filter()
            ->filter(function (array $rule) {
                $field = $rule['field'] ?? null;
                $operator = $rule['operator'] ?? null;

                return is_string($field)
                    && is_string($operator)
                    && $this->registry->has($field)
                    && $this->registry->supportsOperator($field, $operator);
            })
            ->map(fn (array $rule) => [
                'field' => $rule['field'],
                'operator' => $rule['operator'],
                'value' => $rule['value'] ?? null,
            ])
            ->values();
    }

    /**
     * Which rules were thrown away, so the UI can say so instead of silently
     * returning a wrong count — a segment that quietly drops a condition is
     * worse than one that errors.
     *
     * @return array<int, string>
     */
    protected function invalidRuleLabels(array $rules): array
    {
        return collect($rules)
            ->filter(fn ($rule) => is_array($rule))
            ->reject(function (array $rule) {
                $field = $rule['field'] ?? null;
                $operator = $rule['operator'] ?? null;

                return is_string($field)
                    && is_string($operator)
                    && $this->registry->has($field)
                    && $this->registry->supportsOperator($field, $operator);
            })
            ->map(fn (array $rule) => (string) ($rule['field'] ?? 'unknown field').' '.(string) ($rule['operator'] ?? ''))
            ->values()
            ->all();
    }
}
