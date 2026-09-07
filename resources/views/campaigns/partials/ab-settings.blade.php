@php
    use App\Services\Campaigns\AbTestService;
    use App\Services\Campaigns\RecipientGenerator;
    use Illuminate\Support\Facades\DB;

    /**
     * The split-test editor (spec 10.4).
     *
     * Deliberately its own <form>, sitting outside the block builder: the
     * builder wraps everything it is given in one form, and HTML has no nested
     * forms. Keeping it separate also means a rejected split test never takes
     * the unsaved email down with it, and vice versa.
     *
     * Everything this screen says about what will happen is read from
     * AbTestService, not invented here:
     *   - assign()   decides the sample by a hash, and falls back to splitting
     *                everybody when the sample would be smaller than the number
     *                of versions. That is the "no holdback" case below.
     *   - boundaries() scales shares that do not add up to 100 rather than
     *                rejecting them, so the effective share is shown.
     *   - pickWinner() compares RATES over each version's own delivered count.
     *   - decideAt() measures the window from the last message the SAMPLE sent.
     */
    $labels = AbTestService::LABELS;
    $maxVariants = count($labels);

    $abExists = $campaign->exists;

    $scalar = function ($row, string $key): string {
        $value = is_array($row) ? ($row[$key] ?? '') : '';

        return is_scalar($value) ? (string) $value : '';
    };

    // How many recipients each saved version already owns. Non-zero locks that
    // version against removal — their opens and clicks are grouped by it.
    $assignedCounts = $abExists
        ? DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_variant_id')
            ->selectRaw('campaign_variant_id, COUNT(*) as aggregate')
            ->groupBy('campaign_variant_id')
            ->pluck('aggregate', 'campaign_variant_id')
        : collect();

    $savedVariants = $abExists ? app(AbTestService::class)->variants($campaign) : collect();

    $rows = $savedVariants->map(fn ($variant) => [
        'uid' => 'v'.$variant->id,
        'id' => (int) $variant->id,
        'label' => (string) $variant->label,
        'subject' => (string) ($variant->subject ?? ''),
        'from_name' => (string) ($variant->from_name ?? ''),
        'from_email' => (string) ($variant->from_email ?? ''),
        'html' => (string) ($variant->html ?? ''),
        'share_percent' => (int) $variant->share_percent,
        'assigned' => (int) ($assignedCounts[$variant->id] ?? 0),
    ])->values()->all();

    // A rejected save must redisplay what was typed, not what is stored.
    $postedVariants = old('variants');

    if (is_array($postedVariants)) {
        $rows = [];

        foreach (array_values($postedVariants) as $i => $posted) {
            if (! is_array($posted)) {
                continue;
            }

            $id = (int) $scalar($posted, 'id');
            $label = mb_strtoupper($scalar($posted, 'label'));

            $rows[] = [
                'uid' => 'o'.$i,
                'id' => $id,
                'label' => in_array($label, $labels, true) ? $label : ($labels[$i] ?? 'A'),
                'subject' => $scalar($posted, 'subject'),
                'from_name' => $scalar($posted, 'from_name'),
                'from_email' => $scalar($posted, 'from_email'),
                'html' => $scalar($posted, 'html'),
                'share_percent' => (int) $scalar($posted, 'share_percent'),
                'assigned' => (int) ($assignedCounts[$id] ?? 0),
            ];
        }
    }

    $abEnabled = (bool) old('is_ab_test', $campaign->is_ab_test);

    $abType = old_text('ab_test_type', (string) ($campaign->ab_test_type ?? 'subject'));
    $abType = in_array($abType, ['subject', 'sender', 'content'], true) ? $abType : 'subject';

    $abMetric = old_text('ab_winner_metric', (string) ($campaign->ab_winner_metric ?: 'opens'));
    $abMetric = in_array($abMetric, ['opens', 'clicks'], true) ? $abMetric : 'opens';

    $abSample = (int) old_text('ab_sample_percent', (string) ($campaign->ab_sample_percent ?: 20));
    $abSample = max(1, min(100, $abSample));

    $abWindow = (int) old_text('ab_decide_after_minutes', (string) ($campaign->ab_decide_after_minutes ?: 240));
    $abWindow = max(5, min(10080, $abWindow));

    // The audience as it stands right now. The same count the review screen
    // uses, so the two screens cannot disagree.
    $abReach = $abExists ? app(RecipientGenerator::class)->count($campaign) : 0;

    // Errors from THIS form only. The campaign editor above shows its own.
    $abErrorKeys = collect($errors->keys())->filter(
        fn ($key) => $key === 'variants' || str_starts_with($key, 'variants.') || str_starts_with($key, 'ab_') || $key === 'is_ab_test'
    );

    $abErrors = $abErrorKeys->flatMap(fn ($key) => $errors->get($key))->unique()->values()->all();

    $abTypeLabels = [
        'subject' => 'Subject line',
        'sender' => 'Sender name and address',
        'content' => 'Content',
    ];

    // A variant may not be removed once recipients carry its id.
    $lockedLabels = collect($rows)->filter(fn ($row) => $row['assigned'] > 0)->pluck('label')->all();
@endphp

<div class="kn-card mt-5" id="split-test">
    <div class="kn-card-header">
        <h2 class="text-sm font-semibold text-ink-900">Split test</h2>
        <span class="text-xs text-ink-500">Saved separately from the email above</span>
    </div>

    @if (! $abExists)
        {{-- No campaign row yet, so a version has nothing to belong to. Said
             plainly rather than offered as controls that would throw away
             everything typed into them on the first save. --}}
        <div class="p-5">
            <p class="text-sm text-ink-600">
                Save this campaign as a draft first. A version has to belong to a campaign, so the split-test
                editor appears here as soon as the draft exists — nothing else about it changes.
            </p>
        </div>
    @else
        @if ($abErrors)
            <div class="border-b border-red-200 bg-red-50 px-5 py-4">
                <p class="text-sm font-semibold text-red-800">The split test was not saved</p>
                <ul class="mt-1 list-inside list-disc space-y-1 text-sm text-red-700">
                    @foreach ($abErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-red-700">
                    The email above was not touched — this form and the campaign form save independently.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('campaigns.ab.update', $campaign) }}"
              x-data="{
                  enabled: {{ $abEnabled ? 'true' : 'false' }},
                  type: @js($abType),
                  sample: {{ $abSample }},
                  metric: @js($abMetric),
                  window: {{ $abWindow }},
                  reach: {{ $abReach }},
                  labels: @js($labels),
                  rows: @js($rows),
                  seq: 0,

                  freeLabel() {
                      return this.labels.find(label => ! this.rows.some(row => row.label === label)) || null;
                  },

                  add() {
                      const label = this.freeLabel();
                      if (! label) return;

                      this.rows.push({
                          uid: 'n' + (++this.seq),
                          id: 0,
                          label: label,
                          subject: '',
                          from_name: '',
                          from_email: '',
                          html: '',
                          share_percent: Math.round(100 / (this.rows.length + 1)),
                          assigned: 0,
                      });
                  },

                  remove(index) {
                      /* Mirrors the server rule rather than trusting the button
                         to have been hidden: a version with recipients stays. */
                      if (this.rows[index]?.assigned > 0) return;
                      this.rows.splice(index, 1);
                  },

                  /* Offered as a button rather than done silently on add or
                     remove — a share somebody typed on purpose should not be
                     overwritten by an unrelated click. */
                  balance() {
                      if (this.rows.length === 0) return;
                      const each = Math.round(100 / this.rows.length);
                      this.rows.forEach(row => row.share_percent = each);
                  },

                  shareTotal() {
                      return this.rows.reduce((sum, row) => sum + Math.max(0, Number(row.share_percent) || 0), 0);
                  },

                  /* What boundaries() will actually use, after scaling to 100. */
                  effectiveShare(row) {
                      const total = this.shareTotal();
                      if (total < 1) return 0;
                      return Math.round(Math.max(0, Number(row.share_percent) || 0) / total * 100);
                  },

                  sampleCount() {
                      return Math.round(this.reach * this.sample / 100);
                  },

                  /* Whatever is actually being divided. In the no-holdback
                     case assign() splits the WHOLE audience, so quoting a
                     share of the sample there would be a number the send will
                     not produce. */
                  pool() {
                      return this.noHoldback() ? this.reach : this.sampleCount();
                  },

                  poolLabel() {
                      return this.noHoldback() ? 'audience' : 'sample';
                  },

                  perVersion(row) {
                      return Math.round(this.pool() * this.effectiveShare(row) / 100);
                  },

                  /* assign() falls back to splitting everybody when the sample
                     would hold fewer contacts than there are versions. */
                  noHoldback() {
                      return this.reach > 0 && this.sampleCount() < this.rows.length;
                  },

                  holdback() {
                      return this.noHoldback() ? 0 : Math.max(0, this.reach - this.sampleCount());
                  },

                  windowLabel() {
                      const minutes = Math.max(5, Math.min(10080, Number(this.window) || 0));
                      if (minutes < 60) return minutes + ' minutes';
                      if (minutes < 1440) return (Math.round(minutes / 6) / 10) + ' hours';
                      return (Math.round(minutes / 144) / 10) + ' days';
                  },

                  number(value) {
                      return new Intl.NumberFormat().format(Math.max(0, Math.round(value)));
                  },
              }"
              x-init="$watch('enabled', on => { if (on && rows.length === 0) { add(); add(); } })">
            @csrf
            @method('PUT')

            {{-- --------------------------------------------------- the switch --}}
            <div class="border-b border-ink-100 p-5">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="is_ab_test" value="1" class="kn-checkbox mt-0.5"
                           x-model="enabled">
                    <span>
                        <span class="text-sm font-medium text-ink-900">Send this as a split test</span>
                        <span class="mt-0.5 block text-sm text-ink-600">
                            Part of the audience receives two to {{ $maxVariants }} versions of this email. The rest
                            wait, and are sent whichever version did best.
                        </span>
                    </span>
                </label>

                <p class="mt-3 text-xs text-ink-500" x-show="! enabled" x-cloak>
                    With this off, everybody gets the one email built above. Any versions below are kept as they are
                    and nothing is sent from them.
                </p>
            </div>

            <div x-show="enabled" x-cloak>

                {{-- ------------------------------------------------- settings --}}
                <div class="grid gap-4 border-b border-ink-100 p-5 sm:grid-cols-2">
                    <div>
                        <label for="ab_test_type" class="kn-label">What changes between versions</label>
                        <select id="ab_test_type" name="ab_test_type" class="kn-select" x-model="type">
                            @foreach ($abTypeLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="kn-help">
                            Only the fields for this choice are used. The others are still stored, so switching back
                            does not lose what you typed.
                        </p>
                        <x-input-error :messages="$errors->get('ab_test_type')" />
                    </div>

                    <div>
                        <label for="ab_winner_metric" class="kn-label">The winner is the version with the best</label>
                        <select id="ab_winner_metric" name="ab_winner_metric" class="kn-select" x-model="metric">
                            <option value="opens">Open rate</option>
                            <option value="clicks">Click rate</option>
                        </select>
                        <p class="kn-help">
                            A rate, over that version's own delivered count — never a total, or the version that got
                            the bigger share would win whatever it said.
                        </p>
                        <x-input-error :messages="$errors->get('ab_winner_metric')" />
                    </div>

                    <div>
                        <label for="ab_sample_percent" class="kn-label">Share of the audience used for the test</label>
                        <div class="flex items-center gap-2">
                            <input id="ab_sample_percent" name="ab_sample_percent" type="number" min="1" max="100"
                                   class="kn-input" x-model.number="sample">
                            <span class="text-sm text-ink-500">%</span>
                        </div>
                        <p class="kn-help">
                            The rest of the audience is held back until a winner is known.
                        </p>
                        <x-input-error :messages="$errors->get('ab_sample_percent')" />
                    </div>

                    <div>
                        <label for="ab_decide_after_minutes" class="kn-label">Wait this long before deciding</label>
                        <div class="flex items-center gap-2">
                            <input id="ab_decide_after_minutes" name="ab_decide_after_minutes" type="number"
                                   min="5" max="10080" class="kn-input" x-model.number="window">
                            <span class="whitespace-nowrap text-sm text-ink-500">minutes</span>
                        </div>
                        <p class="kn-help">
                            Between 5 minutes and 7 days. Counted from the last message in the <em>sample</em> that
                            actually went out — not from when you press send.
                        </p>
                        <x-input-error :messages="$errors->get('ab_decide_after_minutes')" />
                    </div>
                </div>

                {{-- -------------------------------------------- what will happen --}}
                <div class="border-b border-ink-100 bg-ink-50 p-5">
                    <p class="text-sm font-semibold text-ink-900">What this will actually do</p>

                    <template x-if="reach === 0">
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            No contact matches this campaign's audience yet, so there is nothing to split and no
                            numbers to show. Pick lists, tags or segments on the Audience tab above, save the draft,
                            and the figures here fill in.
                        </p>
                    </template>

                    <template x-if="reach > 0 && rows.length < 2">
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            A split test needs at least two versions to compare. Add another one below — the campaign
                            cannot be sent as a split test until it has one.
                        </p>
                    </template>

                    <template x-if="reach > 0 && rows.length >= 2 && ! noHoldback()">
                        <p class="mt-2 text-sm text-ink-700">
                            About <span class="font-semibold" x-text="number(sampleCount())"></span> of your
                            <span class="font-semibold" x-text="number(reach)"></span> contacts are split between the
                            <span x-text="rows.length"></span> versions. The other
                            <span class="font-semibold" x-text="number(holdback())"></span> are held back and receive
                            nothing until a winner is picked, roughly <span x-text="windowLabel()"></span> after the
                            sample has finished going out. Then the whole holdback is sent the winning version.
                        </p>
                    </template>

                    {{-- The honest version of a split test on a small list. --}}
                    <template x-if="noHoldback()">
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <span class="font-semibold" x-text="sample + '% of ' + number(reach) + ' contacts is about ' + number(sampleCount()) + ' —'"></span>
                            fewer than the <span x-text="rows.length"></span> versions being compared. This will not be
                            a test with a holdback: the whole audience is split between the versions instead, and there
                            is nobody left over to send the winner to. A winner is still recorded on the report, but it
                            changes nothing about who received what. Raise the percentage or use a larger audience if
                            you wanted a holdback.
                        </p>
                    </template>

                    <ul class="mt-3 space-y-1.5 text-xs text-ink-600">
                        <li>
                            Who lands in the sample is decided by a hash of the campaign and the contact — not signup
                            order, not chance. Re-running it gives the same answer, so a send already under way cannot
                            be reshuffled.
                        </li>
                        <li>
                            Nothing here decides anything while this page is open. A scheduled task looks every minute
                            for tests whose window has closed and whose sample has fully gone out.
                        </li>
                        <li>
                            Pausing or cancelling the campaign stops the holdback going out. A cancelled test never
                            decides.
                        </li>
                        <li>
                            A version that delivered nothing cannot win, and if no version delivered anything the test
                            simply does not decide.
                        </li>
                    </ul>
                </div>

                {{-- --------------------------------------------------- versions --}}
                <div class="p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-ink-900">Versions</p>
                            <p class="text-xs text-ink-500">
                                Anything left blank falls back to the campaign's own value above — that is how you keep
                                one version as the control.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" class="kn-btn-ghost kn-btn-sm" @click="balance()"
                                    x-show="rows.length > 1" x-cloak>
                                Split evenly
                            </button>

                            <button type="button" class="kn-btn-secondary kn-btn-sm" @click="add()"
                                    :disabled="rows.length >= {{ $maxVariants }}"
                                    :class="rows.length >= {{ $maxVariants }} ? 'opacity-50 cursor-not-allowed' : ''">
                                Add a version
                            </button>
                        </div>
                    </div>

                    <p class="mb-3 text-xs text-ink-500" x-show="rows.length >= {{ $maxVariants }}" x-cloak>
                        {{ $maxVariants }} versions is the maximum — they are lettered {{ implode(', ', $labels) }}.
                    </p>

                    <template x-if="rows.length === 0">
                        <p class="rounded-lg border border-dashed border-ink-300 px-4 py-8 text-center text-sm text-ink-500">
                            No versions yet. Add two to compare.
                        </p>
                    </template>

                    <div class="space-y-4">
                        <template x-for="(row, index) in rows" :key="row.uid">
                            <div class="rounded-lg border border-ink-200 p-4">
                                <input type="hidden" :name="'variants[' + index + '][id]'" :value="row.id">
                                <input type="hidden" :name="'variants[' + index + '][label]'" :value="row.label">

                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <span class="grid h-7 w-7 place-items-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700"
                                              x-text="row.label"></span>
                                        <span class="text-sm font-medium text-ink-900">Version <span x-text="row.label"></span></span>
                                        <span class="kn-badge-gray" x-show="row.assigned > 0" x-cloak>
                                            <span x-text="number(row.assigned) + ' recipient(s) assigned'"></span>
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-3">
                                        <label class="flex items-center gap-2 text-xs text-ink-600">
                                            <span>Share of the sample</span>
                                            <input type="number" min="0" max="100"
                                                   class="kn-input w-20 py-1 text-sm"
                                                   :name="'variants[' + index + '][share_percent]'"
                                                   x-model.number="row.share_percent">
                                            <span>%</span>
                                        </label>

                                        <template x-if="row.assigned === 0">
                                            <button type="button" class="kn-btn-ghost kn-btn-sm text-red-600"
                                                    @click="remove(index)">Remove</button>
                                        </template>
                                    </div>
                                </div>

                                {{-- Why a version can be stuck. --}}
                                <template x-if="row.assigned > 0">
                                    <p class="mt-2 rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600">
                                        This version cannot be removed. <span x-text="number(row.assigned)"></span>
                                        recipient(s) already carry it, and the report groups their opens and clicks by
                                        it — deleting it would leave those numbers with nothing to be numbers of.
                                        Duplicate the campaign if you want to start the test over.
                                    </p>
                                </template>

                                {{-- subject test --}}
                                <div class="mt-3" x-show="type === 'subject'" x-cloak>
                                    <label class="kn-label">Subject line for this version</label>
                                    <input type="text" maxlength="255" class="kn-input"
                                           :name="'variants[' + index + '][subject]'"
                                           x-model="row.subject"
                                           :placeholder="@js($campaign->subject ?: 'The campaign subject line')">
                                    <p class="kn-help">Blank means this version uses the campaign's subject line.</p>
                                </div>

                                {{-- sender test --}}
                                <div class="mt-3 grid gap-3 sm:grid-cols-2" x-show="type === 'sender'" x-cloak>
                                    <div>
                                        <label class="kn-label">From name</label>
                                        <input type="text" maxlength="191" class="kn-input"
                                               :name="'variants[' + index + '][from_name]'"
                                               x-model="row.from_name"
                                               :placeholder="@js($campaign->from_name ?: 'The campaign from name')">
                                    </div>
                                    <div>
                                        <label class="kn-label">From address</label>
                                        <input type="email" maxlength="191" class="kn-input"
                                               :name="'variants[' + index + '][from_email]'"
                                               x-model="row.from_email"
                                               :placeholder="@js($campaign->from_email ?: 'The campaign from address')">
                                    </div>
                                    <p class="kn-help sm:col-span-2">
                                        Either field blank means this version uses the campaign's. Changing the from
                                        address can move a version into a different reputation — that is part of what
                                        a sender test measures, and part of why it can be noisy.
                                    </p>
                                </div>

                                {{-- content test --}}
                                <div class="mt-3" x-show="type === 'content'" x-cloak>
                                    <label class="kn-label">HTML for this version</label>
                                    <textarea rows="6" class="kn-textarea font-mono text-xs"
                                              :name="'variants[' + index + '][html]'"
                                              x-model="row.html"
                                              placeholder="Leave blank to use the email built above"></textarea>
                                    <p class="kn-help">
                                        Blank means this version sends the email built above, which is how the control
                                        version is written. If you do paste HTML here it must contain
                                        <code>@{{unsubscribe_link}}</code> — the check on the campaign body does not
                                        cover a version that replaces it, and the save is refused without one.
                                    </p>
                                </div>

                                {{-- live arithmetic for this row --}}
                                <div class="mt-3 text-xs text-ink-500" x-show="reach > 0" x-cloak>
                                    <template x-if="shareTotal() >= 1">
                                        <span>
                                            Gets <span class="font-semibold" x-text="effectiveShare(row) + '%'"></span>
                                            of the <span x-text="poolLabel()"></span> — about
                                            <span class="font-semibold" x-text="number(perVersion(row))"></span>
                                            contact(s).
                                        </span>
                                    </template>
                                    <template x-if="shareTotal() < 1">
                                        <span class="text-amber-700">
                                            Every version is set to 0%, so there is nothing to divide.
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- shares that do not add to 100 --}}
                    <p class="mt-3 rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600"
                       x-show="rows.length > 0 && shareTotal() !== 100 && shareTotal() >= 1" x-cloak>
                        Your shares add up to <span class="font-semibold" x-text="shareTotal() + '%'"></span>, not 100.
                        That is allowed — they are scaled to fit, and the last version closes the range so no contact
                        is left in a rounding gap. The percentages shown against each version above are the ones that
                        will actually be used.
                    </p>
                </div>
            </div>

            {{-- ------------------------------------------------------- save --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-5 py-4">
                <p class="text-xs text-ink-500">
                    This saves only the split test. The email above has its own Save.
                    @if ($lockedLabels)
                        Version{{ count($lockedLabels) === 1 ? '' : 's' }}
                        {{ implode(', ', $lockedLabels) }} already {{ count($lockedLabels) === 1 ? 'has' : 'have' }}
                        recipients and cannot be removed.
                    @endif
                </p>
                <button type="submit" class="kn-btn-primary">Save split test</button>
            </div>
        </form>
    @endif
</div>
