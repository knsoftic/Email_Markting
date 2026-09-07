<x-admin-layout>
    <x-slot name="header">Plans</x-slot>

    <x-page-header title="Subscription plans"
                   subtitle="Every limit and feature below is enforced at runtime — nothing here is cosmetic.">
        <x-slot name="actions">
            <a href="{{ route('admin.plans.create') }}" class="kn-btn-primary">New plan</a>
        </x-slot>
    </x-page-header>

    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Contacts</th>
                        <th>Emails / month</th>
                        <th>SMTP</th>
                        <th>Mailboxes</th>
                        <th>Features</th>
                        <th>Subscribers</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr>
                            <td>
                                <div class="font-medium text-ink-900">{{ $plan->name }}</div>
                                <div class="text-xs text-ink-500">{{ $plan->slug }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $plan->currency }} {{ number_format((float) $plan->price, 2) }}
                                <span class="text-xs text-ink-500">/ {{ $plan->billing_period }}</span>
                            </td>
                            <td>{{ $plan->max_contacts === null ? 'Unlimited' : number_format($plan->max_contacts) }}</td>
                            <td>{{ $plan->max_emails_per_month === null ? 'Unlimited' : number_format($plan->max_emails_per_month) }}</td>
                            <td>
                                {{ $plan->allow_custom_smtp ? ($plan->max_smtp_accounts === null ? 'Unlimited' : $plan->max_smtp_accounts) : 'Admin only' }}
                                @if ($plan->allow_smtp_rotation)
                                    <span class="kn-badge-blue ml-1">Rotation</span>
                                @endif
                            </td>
                            <td>{{ $plan->max_mailboxes === null ? 'Unlimited' : $plan->max_mailboxes }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @if ($plan->allow_automation) <span class="kn-badge-gray">Automation</span> @endif
                                    @if ($plan->allow_ab_testing) <span class="kn-badge-gray">A/B</span> @endif
                                    @if ($plan->allow_segments) <span class="kn-badge-gray">Segments</span> @endif
                                    @if ($plan->allow_advanced_analytics) <span class="kn-badge-gray">Advanced</span> @endif
                                </div>
                            </td>
                            <td>{{ number_format($plan->active_subscriptions) }}</td>
                            <td>
                                <span class="{{ $plan->is_active ? 'kn-badge-green' : 'kn-badge-gray' }}">
                                    {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if ($plan->is_default)
                                    <span class="kn-badge-blue mt-1 block w-fit">Default</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('admin.plans.edit', $plan) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                    <form method="POST" action="{{ route('admin.plans.duplicate', $plan) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="kn-btn-ghost kn-btn-sm">Duplicate</button>
                                    </form>

                                    <x-confirm-form :action="route('admin.plans.destroy', $plan)"
                                                    label="Delete"
                                                    message="Delete this plan? Plans with active subscriptions are deactivated instead." />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <x-empty-state title="No plans yet"
                                               message="Create the first plan so accounts have something to subscribe to.">
                                    <x-slot name="action">
                                        <a href="{{ route('admin.plans.create') }}" class="kn-btn-primary">New plan</a>
                                    </x-slot>
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($plans->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $plans->links() }}</div>
        @endif
    </div>
</x-admin-layout>
