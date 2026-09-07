@php
    use App\Services\Campaigns\AbTestService;
    use Illuminate\Support\Facades\DB;

    /**
     * The split-test block on a campaign report (spec 10.4).
     *
     * Everything here comes from AbTestService::results(), which counts the
     * recipient rows rather than reading the denormalised counters — a report
     * that disagrees with the data is worse than no report.
     *
     * The one rule this block keeps above all others: while a test is still
     * open it says what it is waiting for. It never prints a winner that has
     * not been chosen, and never prints a rate over a denominator of zero.
     */
    $abService = app(AbTestService::class);

    $abRows = $abService->results($campaign);
    $abDecided = $campaign->ab_decided_at !== null;
    $abDecideAt = $abService->decideAt($campaign);

    $abSampleSent = (int) $abRows->sum('sent');
    $abSampleAssigned = (int) $abRows->sum('assigned');

    // Sample rows still in flight. Nothing may be decided while any of them are:
    // it would compare a finished version against half of another.
    $abStillSending = DB::table('campaign_recipients')
        ->where('campaign_id', $campaign->id)
        ->whereNotNull('campaign_variant_id')
        ->whereIn('status', ['pending', 'sending'])
        ->exists();

    // The holdback: recipients with no version, still waiting for one.
    $abHoldback = (int) DB::table('campaign_recipients')
        ->where('campaign_id', $campaign->id)
        ->whereNull('campaign_variant_id')
        ->whereIn('status', ['pending', 'sending'])
        ->count();

    $abMetricLabel = $campaign->ab_winner_metric === 'clicks' ? 'click rate' : 'open rate';
    $abTypeLabel = [
        'subject' => 'subject line',
        'sender' => 'sender',
        'content' => 'content',
    ][$campaign->ab_test_type] ?? 'version';

    // A decision is genuinely open only when there is something to decide on
    // and the sample has finished. Anywhere else, offering the control would
    // be offering a button the controller refuses.
    $abDecisionOpen = ! $abDecided && $abSampleSent > 0 && ! $abStillSending;

    $abCanDecide = $abDecisionOpen && (bool) auth()->user()?->hasPermission('campaigns.send');

    $abWinnerId = (int) $campaign->ab_winner_variant_id;
@endphp

@if ($campaign->is_ab_test)
    <div class="kn-card mb-6">
        <div class="kn-card-header">
            <h2 class="text-sm font-semibold text-ink-900">Split test</h2>
            <span class="text-xs text-ink-500">
                {{ ucfirst($abTypeLabel) }} test · winner on {{ $abMetricLabel }}
            </span>
        </div>

        @if ($abRows->isEmpty())
            <div class="p-5 text-sm text-ink-700">
                This campaign is marked as a split test but has no versions saved against it, so there is nothing to
                compare and nothing was ever split. It sends, and reports, as one ordinary campaign.
            </div>
        @else
            {{-- ------------------------------------------------- the state --}}
            <div class="border-b border-ink-100 px-5 py-4 text-sm">
                @if ($abDecided)
                    @php $abWinnerRow = $abRows->firstWhere('is_winner', true); @endphp

                    <p class="text-ink-800">
                        <span class="font-semibold text-ink-900">
                            Version {{ $abWinnerRow['label'] ?? '—' }} won
                        </span>
                        on {{ $abMetricLabel }}, decided
                        {{ $campaign->ab_decided_at->diffForHumans() }}
                        ({{ $campaign->ab_decided_at->format('j M Y, H:i') }}).
                    </p>
                    <p class="mt-1 text-ink-600">
                        Everyone who was still held back was moved onto that version and sent it. The rows below are
                        the sample the decision was taken on, plus whatever the holdback has done since.
                    </p>

                @elseif ($abSampleSent === 0)
                    <p class="text-ink-800">
                        Nothing in the sample has been delivered yet, so there is no rate to compare and the clock has
                        not started. No winner has been chosen and none can be.
                        @if ($abSampleAssigned > 0)
                            {{ number_format($abSampleAssigned) }} recipient(s) are assigned to a version and waiting.
                        @endif
                    </p>

                @elseif ($abStillSending)
                    <p class="text-ink-800">
                        The sample is still going out. The window does not start until the last message in it has been
                        accepted — deciding now would compare a finished version against half of another.
                    </p>

                @elseif ($abDecideAt === null)
                    <p class="text-ink-800">
                        No message in the sample carries a send time, so there is nothing to measure the waiting window
                        from. This test will not decide on its own.
                    </p>

                @elseif ($abDecideAt->isFuture())
                    <p class="text-ink-800">
                        <span class="font-semibold text-ink-900">Waiting.</span>
                        The winner is chosen at {{ $abDecideAt->format('j M Y, H:i') }}
                        ({{ $abDecideAt->diffForHumans() }}) — that is
                        {{ number_format((int) ($campaign->ab_decide_after_minutes ?: 240)) }} minutes after the last
                        message in the sample went out.
                    </p>

                @else
                    <p class="text-ink-800">
                        <span class="font-semibold text-ink-900">The window has closed.</span>
                        It ended {{ $abDecideAt->diffForHumans() }}. The scheduled task
                        (<code class="rounded bg-ink-100 px-1 py-0.5 text-xs">campaigns:decide-ab</code>) picks the
                        winner within a minute of that. If this line does not change, that scheduler is not running.
                    </p>
                @endif

                {{-- The holdback, and the honest case where there is not one. --}}
                @if (! $abDecided)
                    <p class="mt-2 text-ink-600">
                        @if ($abHoldback > 0)
                            {{ number_format($abHoldback) }} contact(s) are held back. They have been sent nothing at
                            all and will receive the winning version once it is chosen.
                        @else
                            There is no holdback — every contact in this campaign was given a version. That happens
                            when the audience is too small for the configured sample: a winner is still recorded for
                            the report, but there is nobody left to send it to.
                        @endif
                    </p>
                @endif
            </div>

            {{-- ------------------------------------------------ the numbers --}}
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>What is different</th>
                            <th class="text-right">Assigned</th>
                            <th class="text-right">Sent</th>
                            <th class="text-right">Open rate</th>
                            <th class="text-right">Click rate</th>
                            @if ($abCanDecide)
                                <th class="text-right">Decide</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($abRows as $row)
                            @php
                                $variant = $row['variant'];

                                $difference = match ($campaign->ab_test_type) {
                                    'subject' => $variant->subject ?: 'The campaign subject line',
                                    'sender' => trim(($variant->from_name ?: $campaign->from_name).' <'
                                        .($variant->from_email ?: $campaign->from_email).'>'),
                                    'content' => filled($variant->html)
                                        ? 'Its own content'
                                        : 'The campaign content',
                                    default => '—',
                                };
                            @endphp

                            <tr class="{{ $abWinnerId === (int) $variant->id ? 'bg-emerald-50/60' : '' }}">
                                <td>
                                    <span class="font-medium text-ink-900">Version {{ $variant->label }}</span>
                                    @if ($abWinnerId === (int) $variant->id)
                                        <span class="kn-badge-green ml-1.5">Winner</span>
                                    @endif
                                </td>

                                <td class="max-w-xs">
                                    <span class="block truncate text-ink-600" title="{{ $difference }}">{{ $difference }}</span>
                                </td>

                                <td class="text-right text-ink-600">{{ number_format($row['assigned']) }}</td>
                                <td class="text-right text-ink-600">{{ number_format($row['sent']) }}</td>

                                {{-- Every rate divides by this version's own sent count, so a
                                     version that delivered nothing prints why, not 0%. --}}
                                <td class="text-right">
                                    @if ($row['sent'] > 0)
                                        <span class="font-medium text-ink-900">{{ $row['open_rate'] }}%</span>
                                        <span class="block text-xs text-ink-400">
                                            {{ number_format($row['opens']) }} of {{ number_format($row['sent']) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-ink-400">Nothing delivered</span>
                                    @endif
                                </td>

                                <td class="text-right">
                                    @if ($row['sent'] > 0)
                                        <span class="font-medium text-ink-900">{{ $row['click_rate'] }}%</span>
                                        <span class="block text-xs text-ink-400">
                                            {{ number_format($row['clicks']) }} of {{ number_format($row['sent']) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-ink-400">Nothing delivered</span>
                                    @endif
                                </td>

                                @if ($abCanDecide)
                                    <td class="text-right">
                                        <x-confirm-form :action="route('campaigns.ab.decide', $campaign)"
                                                        method="POST"
                                                        label="Choose this"
                                                        button-class="kn-btn-secondary kn-btn-sm"
                                                        :message="'Choose version '.$variant->label.' as the winner of this split test?'
                                                            .($abHoldback > 0
                                                                ? ' The '.number_format($abHoldback).' held-back contact(s) will be sent that version immediately, and this cannot be undone.'
                                                                : ' There is no holdback, so nothing more is sent — this only records the winner on the report.')
                                                            .($row['sent'] === 0
                                                                ? ' Note that this version delivered nothing, so there is no evidence behind the choice.'
                                                                : '')">
                                            <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                                        </x-confirm-form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                Counted from the recipient rows themselves, not from the cached campaign totals. Rates are unique
                openers and clickers over that version's own delivered count — never totals, which would simply reward
                whichever version got the bigger share of the sample.
                @if ($abDecisionOpen && ! $abCanDecide)
                    <span class="mt-1 block">
                        Ending this test early needs the campaigns.send permission, which your role does not have.
                    </span>
                @elseif ($abCanDecide)
                    <span class="mt-1 block">
                        Choosing a version above ends the test now instead of waiting for the window to close.
                    </span>
                @endif
            </div>
        @endif
    </div>
@endif
