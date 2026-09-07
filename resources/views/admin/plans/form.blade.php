<x-admin-layout>
    <x-slot name="header">{{ $plan->exists ? 'Edit plan' : 'New plan' }}</x-slot>

    @php
        $limitFields = [
            'max_contacts' => ['Contacts', 'Total subscribers the account may hold.'],
            'max_emails_per_month' => ['Emails per month', 'Campaign + automation sends per billing month.'],
            'max_emails_per_day' => ['Emails per day', 'Daily send ceiling across all SMTP accounts.'],
            'max_emails_received_per_month' => ['Emails received per month', 'Messages pulled in by IMAP sync.'],
            'max_campaigns_per_month' => ['Campaigns per month', 'New campaigns created per month.'],
            'max_smtp_accounts' => ['SMTP accounts', 'Only applies when custom SMTP is allowed.'],
            'max_mailboxes' => ['Mailboxes', 'Connected IMAP inboxes.'],
            'max_lists' => ['Lists', 'Subscriber lists.'],
            'max_templates' => ['Templates', 'Saved email templates.'],
            'max_automations' => ['Automations', 'Only applies when automation is allowed.'],
            'max_team_members' => ['Team members', 'Users inside the account, owner included.'],
            'max_storage_mb' => ['Storage (MB)', 'Attachments and uploaded images.'],
        ];

        $featureFields = [
            'allow_custom_smtp' => ['Custom SMTP', 'Account may add its own SMTP credentials.'],
            'allow_smtp_rotation' => ['SMTP rotation', 'Rotate across several SMTP accounts by limit.'],
            'allow_admin_smtp' => ['Admin SMTP', 'May send through platform-provided SMTP.'],
            'allow_imap' => ['IMAP inbox', 'May connect mailboxes and receive email.'],
            'allow_automation' => ['Automation', 'Automation builder and triggers.'],
            'allow_ab_testing' => ['A/B testing', 'Subject, sender and content variants.'],
            'allow_advanced_analytics' => ['Advanced analytics', 'Per-recipient drill-down and full reports.'],
            'allow_segments' => ['Segments', 'Saved advanced subscriber filters.'],
            'allow_custom_fields' => ['Custom fields', 'Account-defined subscriber attributes.'],
            'allow_attachments' => ['Attachments', 'Attach files when composing email.'],
            'allow_scheduling' => ['Scheduling', 'Schedule campaigns for a future date.'],
            'allow_template_builder' => ['Template builder', 'Visual block-based template editor.'],
            'allow_api' => ['API access', 'Reserved for the public API.'],
        ];
    @endphp

    <x-page-header :title="$plan->exists ? 'Edit '.$plan->name : 'New plan'"
                   subtitle="Leave a limit blank for unlimited. Enter 0 to block that capability entirely."
                   :back="route('admin.plans.index')" />

    <form method="POST"
          action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}"
          class="space-y-6">
        @csrf
        @if ($plan->exists) @method('PUT') @endif

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Basics</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Plan name" />
                    <x-text-input id="name" name="name" :value="old('name', $plan->name)" required />
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="slug" value="Slug" />
                    <x-text-input id="slug" name="slug" :value="old('slug', $plan->slug)" placeholder="auto from name" />
                    <p class="kn-help">Used in URLs and by the default-plan setting.</p>
                    <x-input-error :messages="$errors->get('slug')" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="description" value="Description" />
                    <x-text-input id="description" name="description" :value="old('description', $plan->description)" />
                    <x-input-error :messages="$errors->get('description')" />
                </div>

                <div>
                    <x-input-label for="price" value="Price" />
                    <x-text-input id="price" name="price" type="number" step="0.01" min="0"
                                  :value="old('price', $plan->price ?? 0)" required />
                    <x-input-error :messages="$errors->get('price')" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="currency" value="Currency" />
                        <x-text-input id="currency" name="currency" maxlength="3"
                                      :value="old('currency', $plan->currency ?? 'USD')" required />
                        <x-input-error :messages="$errors->get('currency')" />
                    </div>
                    <div>
                        <x-input-label for="billing_period" value="Billing" />
                        <select id="billing_period" name="billing_period" class="kn-select">
                            @foreach (['monthly', 'yearly', 'lifetime'] as $period)
                                <option value="{{ $period }}" @selected(old('billing_period', $plan->billing_period) === $period)>
                                    {{ ucfirst($period) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <x-input-label for="trial_days" value="Trial days" />
                    <x-text-input id="trial_days" name="trial_days" type="number" min="0"
                                  :value="old('trial_days', $plan->trial_days ?? 0)" required />
                    <x-input-error :messages="$errors->get('trial_days')" />
                </div>

                <div>
                    <x-input-label for="sort_order" value="Sort order" />
                    <x-text-input id="sort_order" name="sort_order" type="number" min="0"
                                  :value="old('sort_order', $plan->sort_order ?? 0)" required />
                </div>

                <div class="sm:col-span-2 flex flex-wrap gap-6 pt-1">
                    @foreach ([
                        'is_active' => 'Active — can be assigned',
                        'is_public' => 'Public — shown on pricing pages',
                        'is_default' => 'Default — used for new registrations',
                    ] as $key => $label)
                        <label class="inline-flex items-center gap-2 text-sm text-ink-700">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <input type="checkbox" name="{{ $key }}" value="1" class="kn-checkbox"
                                   @checked(old($key, $plan->{$key}))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Limits</h3>
                <span class="text-xs text-ink-500">Blank = unlimited · 0 = not allowed</span>
            </div>
            <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($limitFields as $key => [$label, $help])
                    <div>
                        <x-input-label :for="$key" :value="$label" />
                        <x-text-input :id="$key" :name="$key" type="number" min="0"
                                      :value="old($key, $plan->{$key})" placeholder="Unlimited" />
                        <p class="kn-help">{{ $help }}</p>
                        <x-input-error :messages="$errors->get($key)" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Features</h3>
                <span class="text-xs text-ink-500">Each toggle is checked at runtime</span>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($featureFields as $key => [$label, $help])
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-3 hover:bg-ink-50">
                        <input type="hidden" name="{{ $key }}" value="0">
                        <input type="checkbox" name="{{ $key }}" value="1" class="kn-checkbox mt-0.5"
                               @checked(old($key, $plan->{$key}))>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-ink-800">{{ $label }}</span>
                            <span class="block text-xs text-ink-500">{{ $help }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.plans.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">
                {{ $plan->exists ? 'Save plan' : 'Create plan' }}
            </button>
        </div>
    </form>
</x-admin-layout>
