<x-app-layout>
    <x-slot name="header">Plan &amp; billing</x-slot>

    @php
        /**
         * Whole literal class strings only — never assembled from fragments,
         * so Tailwind's scanner sees every class this page can render.
         */
        $currency = $payment['currency_symbol'] ?: ($payment['currency'] ?: '');

        /**
         * A plan carries its own currency code, and Admin → Payment settings
         * carries the symbol. Nothing keeps the two in step, so a plan created
         * in USD was being priced with whatever symbol the operator had saved —
         * "Rs 5.00" on the customer's screen while Admin → Plans listed the same
         * row as "USD 5.00". The symbol is only used when the two agree; when
         * they do not, the plan's own code is shown, because that is the column
         * that says what the number means.
         */
        $money = function ($plan, int $decimals = 2) use ($currency, $payment) {
            $amount = number_format((float) $plan->price, $decimals);

            return $plan->currency === $payment['currency']
                ? $currency.$amount
                : $plan->currency.' '.$amount;
        };

        $currentPlanId = $plan?->id;
        $hasInstructions = trim((string) $payment['bank_details']) !== ''
            || trim((string) $payment['instructions']) !== '';

        $statusLabels = [
            'trial' => 'Trial', 'active' => 'Active', 'expired' => 'Expired',
            'cancelled' => 'Cancelled', 'pending' => 'Pending',
        ];
    @endphp

    <x-page-header
        title="Plan & billing"
        :subtitle="$plan
            ? 'You are on the '.$plan->name.' plan'
            : 'No plan is assigned to this account yet'" />

    {{-- ------------------------------------------------- current plan --}}
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">

        <div class="kn-card">
            <div class="kn-card-header flex items-center justify-between">
                <h3 class="text-sm font-semibold text-ink-900">What you are using</h3>
                @if ($subscription)
                    <x-status-badge :status="$subscription->status" />
                @endif
            </div>

            <div class="p-5">
                @if ($usage === [])
                    <p class="text-sm text-ink-500">
                        There is nothing to measure yet — this account has no limits set.
                    </p>
                @else
                    <div class="space-y-4">
                        @foreach ($usage as $row)
                            <div>
                                <div class="mb-1 flex items-baseline justify-between gap-3">
                                    <span class="text-sm text-ink-700">{{ $row['label'] }}</span>
                                    <span class="text-xs tabular-nums text-ink-500">
                                        @if ($row['adjusted'])
                                            <span class="kn-badge-blue" title="Set for your account, not the plan's own figure">adjusted</span>
                                        @endif
                                        {{ number_format($row['used']) }}
                                        @if ($row['unlimited'])
                                            <span class="text-ink-400">/ no limit</span>
                                        @else
                                            <span class="text-ink-400">/ {{ number_format($row['limit']) }}</span>
                                        @endif
                                    </span>
                                </div>

                                @unless ($row['unlimited'])
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                                        @if ($row['percent'] >= 90)
                                            <div class="h-full rounded-full bg-red-500" style="width: {{ $row['percent'] }}%"></div>
                                        @elseif ($row['percent'] >= 75)
                                            <div class="h-full rounded-full bg-amber-500" style="width: {{ $row['percent'] }}%"></div>
                                        @else
                                            <div class="h-full rounded-full bg-brand-600" style="width: {{ $row['percent'] }}%"></div>
                                        @endif
                                    </div>
                                @endunless
                            </div>
                        @endforeach
                    </div>

                    @if (collect($usage)->contains('adjusted', true))
                        <p class="mt-4 border-t border-ink-100 pt-3 text-xs text-ink-500">
                            Anything marked <span class="kn-badge-blue">adjusted</span> has been set
                            for your account specifically, so it differs from the plan's own figure
                            in the cards below. Your number is the one shown here.
                        </p>
                    @endif
                @endif
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Your plan</h3></div>
            <div class="space-y-3 p-5 text-sm">
                <div>
                    <p class="text-xs uppercase tracking-wide text-ink-400">Plan</p>
                    <p class="text-base font-semibold text-ink-900">{{ $plan?->name ?? 'None' }}</p>
                </div>

                @if ($plan && $plan->price > 0)
                    <div>
                        <p class="text-xs uppercase tracking-wide text-ink-400">Price</p>
                        <p class="text-ink-700">
                            {{ $money($plan) }}
                            <span class="text-ink-500">/ {{ $plan->billing_period ?: 'month' }}</span>
                        </p>
                    </div>
                @endif

                @if ($subscription?->ends_at)
                    <div>
                        <p class="text-xs uppercase tracking-wide text-ink-400">
                            {{ $subscription->status === 'trial' ? 'Trial ends' : 'Renews or expires' }}
                        </p>
                        <p class="text-ink-700">
                            {{ $subscription->ends_at->timezone($account?->timezone ?: config('app.timezone'))->format('j M Y') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------- other plans --}}
    @if ($plans->isNotEmpty())
        <div class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink-900">Plans</h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($plans as $option)
                    @php $isCurrent = $option->id === $currentPlanId; @endphp

                    <div class="kn-card flex flex-col p-5 {{ $isCurrent ? 'ring-2 ring-brand-600' : '' }}">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-ink-900">{{ $option->name }}</h3>
                            @if ($isCurrent)
                                <span class="kn-badge-blue">Your plan</span>
                            @endif
                        </div>

                        <p class="mb-3 text-2xl font-semibold tabular-nums text-ink-900">
                            @if ((float) $option->price <= 0)
                                Free
                            @else
                                {{ $money($option, 0) }}
                                <span class="text-sm font-normal text-ink-500">/ {{ $option->billing_period ?: 'month' }}</span>
                            @endif
                        </p>

                        @if ($option->description)
                            <p class="mb-3 text-sm text-ink-600">{{ $option->description }}</p>
                        @endif

                        <ul class="mb-4 space-y-1 text-sm text-ink-600">
                            @foreach ($shown as $key => $label)
                                @php $value = $option->{$key}; @endphp
                                @continue ($value === 0 || $value === false)
                                <li class="flex justify-between gap-3">
                                    <span>{{ $label }}</span>
                                    <span class="tabular-nums text-ink-800">
                                        {{ $value === null ? 'No limit' : number_format($value) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        @unless ($isCurrent)
                            <form method="POST" action="{{ route('billing.request') }}" class="mt-auto">
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $option->id }}">
                                <x-secondary-button type="submit" class="w-full justify-center">
                                    Ask about {{ $option->name }}
                                </x-secondary-button>
                            </form>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------- payment --}}
    <div class="mt-6 kn-card">
        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">How payment works</h3></div>

        <div class="p-5">
            {{-- Said plainly. A screen with plan cards and a button looks like a
                 shop, and somebody who pressed the button expecting to have
                 bought something would be right to be annoyed. --}}
            <p class="mb-4 text-sm text-ink-600">
                Payment is handled by us directly — there is no card checkout here. Ask about a plan
                below and we will get in touch; your new plan starts once payment is settled.
                <strong class="text-ink-800">Pressing a button on this page never changes your plan
                or charges you.</strong>
            </p>

            @if ($hasInstructions)
                <div class="grid gap-5 sm:grid-cols-2">
                    @if (trim((string) $payment['bank_details']) !== '')
                        <div>
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-500">Where to pay</p>
                            <p class="whitespace-pre-line text-sm text-ink-700">{{ $payment['bank_details'] }}</p>
                        </div>
                    @endif

                    @if (trim((string) $payment['instructions']) !== '')
                        <div>
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-500">After you pay</p>
                            <p class="whitespace-pre-line text-sm text-ink-700">{{ $payment['instructions'] }}</p>
                        </div>
                    @endif
                </div>
            @else
                {{-- No empty box pretending to be instructions: if the operator
                     has not filled them in, say so and give the way through. --}}
                <p class="text-sm text-ink-600">
                    Payment details have not been published yet.
                    @if ($supportEmail)
                        Email <a href="mailto:{{ $supportEmail }}" class="font-medium text-brand-700 hover:text-brand-800">{{ $supportEmail }}</a>
                        and we will send them to you.
                    @else
                        Get in touch and we will send them to you.
                    @endif
                </p>
            @endif
        </div>
    </div>

    {{-- --------------------------------------------------- free-form ask --}}
    <div class="mt-6 kn-card">
        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Ask a question</h3></div>

        <form method="POST" action="{{ route('billing.request') }}" class="p-5">
            @csrf

            <x-input-label for="note" value="Anything you want us to know" />
            <textarea id="note" name="note" rows="3" maxlength="500"
                      class="mt-1 block w-full rounded-md border-ink-200 text-sm focus:border-brand-500 focus:ring-brand-500"
                      placeholder="We need about 40,000 contacts from next month…">{{ old_text('note') }}</textarea>
            <x-input-error :messages="$errors->get('note')" class="mt-1" />

            <div class="mt-3">
                <x-primary-button type="submit">Send</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
