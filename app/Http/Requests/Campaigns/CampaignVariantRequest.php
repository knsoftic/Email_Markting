<?php

namespace App\Http\Requests\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Services\Campaigns\AbTestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The split-test settings and the whole set of versions, saved together.
 *
 * One post rather than a row-at-a-time API on purpose: the shares, the sample
 * and the versions only make sense as a set, and saving them separately would
 * let a campaign sit for a moment in a state — one version, or shares that add
 * up to nothing — that the sender would have to guess its way out of.
 */
class CampaignVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->hasPermission('campaigns.update')) {
            return false;
        }

        // Same rule as the campaign editor itself, and refused here for what it
        // is rather than reported as a field error: changing which versions
        // exist while messages are going out would move recipients between
        // groups that have already been mailed different email.
        abort_unless($this->campaign()->isEditable(), 403,
            'This campaign is sending or already sent. Duplicate it to make changes.');

        return true;
    }

    public function campaign(): Campaign
    {
        return $this->route('campaign');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_ab_test' => ['boolean'],
            'ab_test_type' => ['nullable', Rule::in(['subject', 'sender', 'content'])],
            'ab_sample_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'ab_winner_metric' => ['required', Rule::in(['opens', 'clicks'])],

            // Capped at a week. AbTestService only floors the value, so a
            // typo of 100000 would park the holdback for ten weeks with
            // nothing on screen ever explaining why it had not gone out.
            'ab_decide_after_minutes' => ['required', 'integer', 'min:5', 'max:10080'],

            'variants' => ['array', 'max:'.count(AbTestService::LABELS)],
            'variants.*' => ['array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.label' => ['required', 'string', Rule::in(AbTestService::LABELS)],
            'variants.*.subject' => ['nullable', 'string', 'max:255'],
            'variants.*.from_name' => ['nullable', 'string', 'max:191'],
            'variants.*.from_email' => ['nullable', 'email:rfc', 'max:191'],
            'variants.*.html' => ['nullable', 'string', 'max:2000000'],
            'variants.*.share_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ab_sample_percent.min' => 'The sample has to be at least 1% of the audience.',
            'ab_sample_percent.max' => 'The sample cannot be more than the whole audience.',
            'ab_decide_after_minutes.min' => 'Give the test at least 5 minutes — a shorter window decides on almost no data.',
            'ab_decide_after_minutes.max' => 'The longest window is 10080 minutes (7 days). Beyond that the holdback sits unsent for so long the email is stale by the time it arrives.',
            'variants.max' => 'A split test can compare at most '.count(AbTestService::LABELS).' versions.',
            'variants.*.share_percent.required' => 'Every version needs a share of the sample.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $variants = $this->input('variants');
        $variants = is_array($variants) ? $variants : [];

        $clean = [];

        foreach ($variants as $row) {
            if (! is_array($row)) {
                continue;
            }

            // Every field is flattened to a scalar before a rule ever sees it.
            // variants[0][subject][] = x would otherwise reach max:255 as an
            // array, and reach the redisplayed form as one too.
            $clean[] = [
                'id' => $this->scalar($row, 'id') === '' ? null : (int) $this->scalar($row, 'id'),
                'label' => mb_strtoupper($this->scalar($row, 'label')),
                'subject' => $this->scalar($row, 'subject'),
                'from_name' => $this->scalar($row, 'from_name'),
                'from_email' => mb_strtolower(trim($this->scalar($row, 'from_email'))),
                'html' => $this->scalar($row, 'html'),
                'share_percent' => $this->scalar($row, 'share_percent') === ''
                    ? null
                    : (int) $this->scalar($row, 'share_percent'),
            ];
        }

        $this->merge([
            'is_ab_test' => $this->boolean('is_ab_test'),
            'variants' => $clean,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function scalar(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkLabels($validator),
            fn (Validator $validator) => $this->checkOwnership($validator),
            fn (Validator $validator) => $this->checkRemovals($validator),
            fn (Validator $validator) => $this->checkContent($validator),
            fn (Validator $validator) => $this->checkTestIsRunnable($validator),
        ];
    }

    /** Two versions cannot both be "A" — the table's unique index says so too. */
    protected function checkLabels(Validator $validator): void
    {
        $labels = [];

        foreach ($this->rows() as $i => $row) {
            $label = (string) ($row['label'] ?? '');

            if ($label !== '' && in_array($label, $labels, true)) {
                $validator->errors()->add("variants.{$i}.label",
                    "Version {$label} is listed twice. Each version needs its own letter.");
            }

            $labels[] = $label;
        }
    }

    /** A posted id has to be one of THIS campaign's versions. */
    protected function checkOwnership(Validator $validator): void
    {
        $own = $this->existing()->keys()->all();

        foreach ($this->rows() as $i => $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0 && ! in_array($id, $own, true)) {
                $validator->errors()->add("variants.{$i}.id",
                    'One of the versions in this form does not belong to this campaign. Reload the page and try again.');
            }
        }
    }

    /**
     * A version that recipients have already been assigned to may not be
     * removed.
     *
     * The rows in campaign_recipients carry campaign_variant_id, and every
     * open and click on the report is grouped by it. Deleting the version
     * cascades those rows' meaning away: the report would still show the
     * opens, and no longer be able to say which email they were opens of.
     */
    protected function checkRemovals(Validator $validator): void
    {
        $kept = collect($this->rows())->pluck('id')->filter()->map('intval')->all();

        foreach ($this->existing() as $id => $variant) {
            if (in_array((int) $id, $kept, true)) {
                continue;
            }

            $assigned = DB::table('campaign_recipients')
                ->where('campaign_id', $this->campaign()->id)
                ->where('campaign_variant_id', $id)
                ->count();

            if ($assigned > 0) {
                $validator->errors()->add('variants',
                    "Version {$variant->label} cannot be removed: ".number_format($assigned)
                    .' recipient(s) have already been assigned to it, and the report for this campaign would no longer'
                    .' be able to say which email their opens and clicks belong to. Duplicate the campaign if you want'
                    .' to start the test over.');
            }
        }
    }

    /**
     * A version that carries its own body carries its own unsubscribe link.
     *
     * CampaignDispatcher::blockers() checks the campaign's html for an opt-out
     * and never sees a variant's, so without this a content test could mail
     * half the audience an email with no way out of the list.
     */
    protected function checkContent(Validator $validator): void
    {
        foreach ($this->rows() as $i => $row) {
            $html = (string) ($row['html'] ?? '');

            if ($html === '') {
                continue;
            }

            if (! str_contains($html, '{{unsubscribe_link}}') && ! str_contains($html, '/unsubscribe/')) {
                $validator->errors()->add("variants.{$i}.html",
                    'This version has its own content but no unsubscribe link. Add {{unsubscribe_link}} to it — '
                    .'the check on the campaign body does not cover a version that replaces it.');
            }
        }
    }

    /** What a switched-on split test needs before it can mean anything. */
    protected function checkTestIsRunnable(Validator $validator): void
    {
        if (! $this->boolean('is_ab_test')) {
            return;
        }

        $rows = $this->rows();

        if (count($rows) < 2) {
            $validator->errors()->add('variants',
                'A split test compares at least two versions. Add another one, or switch the split test off.');
        }

        if (blank($this->input('ab_test_type'))) {
            $validator->errors()->add('ab_test_type',
                'Choose what this test changes — the subject line, the sender, or the content.');
        }

        // boundaries() divides by max(1, sum), so an all-zero set does not
        // error: it silently hands the whole sample to the last version.
        $shares = collect($rows)->sum(fn ($row) => max(0, (int) ($row['share_percent'] ?? 0)));

        if (count($rows) >= 2 && $shares < 1) {
            $validator->errors()->add('variants',
                'Every version is set to 0% of the sample, so there is nothing to split. Give at least one version a share.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        $rows = $this->input('variants');

        return is_array($rows) ? $rows : [];
    }

    /**
     * This campaign's saved versions, keyed by id.
     *
     * @return Collection<int, CampaignVariant>
     */
    protected function existing(): Collection
    {
        return CampaignVariant::query()
            ->where('campaign_id', $this->campaign()->id)
            ->get()
            ->keyBy('id');
    }
}
