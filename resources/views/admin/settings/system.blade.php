<x-admin-layout>
    <x-slot name="header">System settings</x-slot>

    <x-page-header title="System settings"
                   subtitle="Controls that change how the platform behaves for every account." />

    <form method="POST" action="{{ route('admin.settings.system.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Registration</h3></div>
            <div class="space-y-5 p-5">
                <label class="flex items-start gap-3 rounded-lg border border-ink-200 p-3">
                    <input type="hidden" name="allow_registration" value="0">
                    <input type="checkbox" name="allow_registration" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('allow_registration', $values['allow_registration']))>
                    <span>
                        <span class="block text-sm font-medium text-ink-800">Allow public registration</span>
                        <span class="block text-xs text-ink-500">
                            When off, the register page returns 403 and only you can create accounts.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3 rounded-lg border border-ink-200 p-3">
                    <input type="hidden" name="require_email_verification" value="0">
                    <input type="checkbox" name="require_email_verification" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('require_email_verification', $values['require_email_verification']))>
                    <span>
                        <span class="block text-sm font-medium text-ink-800">Require email verification</span>
                        <span class="block text-xs text-ink-500">
                            When on, a new user must click the emailed link before using the app.
                            Super admins are always exempt.
                        </span>
                    </span>
                </label>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="default_plan_slug" value="Default plan for new accounts" />
                        <select id="default_plan_slug" name="default_plan_slug" class="kn-select">
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->slug }}" @selected(old('default_plan_slug', $values['default_plan_slug']) === $plan->slug)>
                                    {{ $plan->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('default_plan_slug')" />
                    </div>

                    <div>
                        <x-input-label for="trial_days" value="Default trial length (days)" />
                        <x-text-input id="trial_days" name="trial_days" type="number" min="0"
                                      :value="old('trial_days', $values['trial_days'])" required />
                        <p class="kn-help">The plan's own trial length wins when it is set.</p>
                        <x-input-error :messages="$errors->get('trial_days')" />
                    </div>
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Environment values</h3></div>
            <div class="p-5">
                <p class="mb-3 text-sm text-ink-600">
                    Sending throughput, sync intervals and attachment limits are deployment-level
                    settings and live in <code class="rounded bg-ink-100 px-1 py-0.5 text-xs">.env</code>,
                    so they can differ per server without a database change.
                </p>
                <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        'Campaign chunk size' => config('knsoftic.campaign_chunk'),
                        'Send rate / minute' => config('knsoftic.send_rate_per_minute'),
                        'Mailbox sync interval' => config('knsoftic.mailbox_sync_minutes').' min',
                        'Mailbox sync limit' => config('knsoftic.mailbox_sync_limit').' messages',
                        'Max attachment' => number_format(config('knsoftic.max_attachment_kb') / 1024, 1).' MB',
                        'SMTP failure threshold' => config('knsoftic.smtp_failure_threshold'),
                        'SMTP cooldown' => config('knsoftic.smtp_cooldown_minutes').' min',
                        'Hard bounce limit' => config('knsoftic.hard_bounce_limit'),
                        'Tracking enabled' => config('knsoftic.tracking_enabled') ? 'Yes' : 'No',
                    ] as $label => $value)
                        <div class="rounded-lg border border-ink-200 px-3 py-2">
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium text-ink-800">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="kn-btn-primary">Save settings</button>
        </div>
    </form>
</x-admin-layout>
