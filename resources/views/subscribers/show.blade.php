<x-app-layout>
    <x-slot name="header">{{ $subscriber->displayName() }}</x-slot>

    @php
        $displayName = $subscriber->displayName();

        // Tag colours are user input, so only a real hex reaches the style attribute.
        $swatch = fn ($color) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? $color : '#64748b';

        $details = [
            'Email' => $subscriber->email,
            'Full name' => $subscriber->name,
            'First name' => $subscriber->first_name,
            'Last name' => $subscriber->last_name,
            'Phone' => $subscriber->phone,
            'Company' => $subscriber->company,
            'Country' => $subscriber->country,
            'City' => $subscriber->city,
            'Timezone' => $subscriber->timezone,
            'Added' => $subscriber->created_at?->format('d M Y H:i'),
            'Last updated' => $subscriber->updated_at?->format('d M Y H:i'),
        ];

        $consentLabels = [
            'explicit' => 'Explicit — actively opted in',
            'implied' => 'Implied — existing relationship',
            'unknown' => 'Unknown — no consent record',
        ];

        $recipientTones = [
            'sent' => 'kn-badge-green',
            'pending' => 'kn-badge-gray',
            'queued' => 'kn-badge-blue',
            'sending' => 'kn-badge-blue',
            'failed' => 'kn-badge-red',
            'bounced' => 'kn-badge-amber',
            'skipped' => 'kn-badge-gray',
        ];

        $isLinkable = fn ($value) => is_string($value)
            && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'));
    @endphp

    <x-page-header :title="$displayName"
                   :subtitle="$displayName === $subscriber->email ? 'Contact' : $subscriber->email"
                   :back="route('subscribers.index')">
        <x-slot name="actions">
            @permission('contacts.update')
                <a href="{{ route('subscribers.edit', $subscriber) }}" class="kn-btn-primary">Edit</a>
            @endpermission

            @permission('contacts.delete')
                <x-confirm-form :action="route('subscribers.destroy', $subscriber)"
                                label="Delete"
                                button-class="kn-btn-danger"
                                :message="'Delete '.$subscriber->email.'? This cannot be undone.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ left --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Contact detail --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Contact detail</h3>
                    <span class="text-xs text-ink-500">ID #{{ $subscriber->id }}</span>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    @foreach ($details as $label => $value)
                        <div class="min-w-0">
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">
                                {{ filled($value) ? $value : '—' }}
                            </dd>
                        </div>
                    @endforeach

                    @foreach ($customFields as $field)
                        @php $value = $subscriber->customValue($field->key); @endphp
                        <div class="min-w-0">
                            <dt class="kn-stat-label">{{ $field->name }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">
                                @if ($field->type === 'boolean')
                                    <span class="{{ (string) $value === '1' ? 'kn-badge-green' : 'kn-badge-gray' }}">
                                        {{ (string) $value === '1' ? 'Yes' : 'No' }}
                                    </span>
                                @elseif (! filled($value))
                                    —
                                @elseif ($field->type === 'url' && $isLinkable($value))
                                    <a href="{{ $value }}" target="_blank" rel="noopener noreferrer"
                                       class="break-all text-brand-600 hover:text-brand-700">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if (filled($subscriber->notes))
                    <div class="border-t border-ink-100 px-5 py-4">
                        <p class="kn-stat-label">Notes</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-ink-700">{{ $subscriber->notes }}</p>
                    </div>
                @endif
            </div>

            {{-- Campaign history --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Campaign history</h3>
                    <span class="text-xs text-ink-500">Last {{ $campaignHistory->count() }} campaign(s)</span>
                </div>

                @if ($campaignHistory->isEmpty())
                    <x-empty-state title="No campaigns yet"
                                   message="Once this contact is included in a send, every delivery, open and click shows up here." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Status</th>
                                    <th>Sent</th>
                                    <th class="text-right">Opens</th>
                                    <th class="text-right">Clicks</th>
                                    <th>Replied</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignHistory as $record)
                                    <tr>
                                        <td>
                                            <div class="min-w-0 max-w-xs">
                                                <span class="block truncate font-medium text-ink-900">
                                                    {{ $record->campaign?->name ?? 'Deleted campaign' }}
                                                </span>
                                                @if ($record->campaign?->subject)
                                                    <span class="block truncate text-xs text-ink-500">{{ $record->campaign->subject }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <span class="{{ $recipientTones[$record->status] ?? 'kn-badge-gray' }}">
                                                {{ ucfirst(str_replace('_', ' ', (string) $record->status)) }}
                                            </span>
                                            @if ($record->bounced_at)
                                                <span class="kn-badge-amber ml-1"
                                                      title="{{ $record->bounced_at->format('d M Y H:i') }}">Bounced</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-xs text-ink-500"
                                            title="{{ $record->sent_at?->format('D, d M Y H:i') }}">
                                            {{ $record->sent_at?->diffForHumans() ?? '—' }}
                                        </td>
                                        <td class="text-right {{ $record->open_count > 0 ? 'font-semibold text-ink-900' : 'text-ink-400' }}">
                                            {{ number_format((int) $record->open_count) }}
                                        </td>
                                        <td class="text-right {{ $record->click_count > 0 ? 'font-semibold text-ink-900' : 'text-ink-400' }}">
                                            {{ number_format((int) $record->click_count) }}
                                        </td>
                                        <td class="whitespace-nowrap text-xs">
                                            @if ($record->replied_at)
                                                <span class="kn-badge-green" title="{{ $record->replied_at->format('D, d M Y H:i') }}">
                                                    {{ $record->replied_at->diffForHumans() }}
                                                </span>
                                            @else
                                                <span class="text-ink-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- =========================================================== right --}}
        <div class="space-y-6">

            {{-- Suppression warning --}}
            @if ($isSuppressed)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-amber-900">On the suppression list</p>
                            <p class="mt-1 text-sm text-amber-800">
                                This address is suppressed for your account, so it will not receive marketing email
                                even while the contact status says otherwise. Sends skip it automatically.
                            </p>
                            @permission('contacts.view')
                                <a href="{{ route('suppressions.index', ['q' => $subscriber->email]) }}"
                                   class="mt-3 inline-block text-sm font-semibold text-amber-900 underline hover:text-amber-950">
                                    Review the suppression entry
                                </a>
                            @endpermission
                        </div>
                    </div>
                </div>
            @endif

            {{-- Status --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Status</h3>
                    <x-status-badge :status="$subscriber->status" />
                </div>
                <div class="space-y-3 p-5 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Mailable</span>
                        <span class="{{ $subscriber->isMailable() && ! $isSuppressed ? 'kn-badge-green' : 'kn-badge-gray' }}">
                            {{ $subscriber->isMailable() && ! $isSuppressed ? 'Yes' : 'No' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Subscribed</span>
                        <span class="text-ink-800">{{ $subscriber->subscribed_at?->format('d M Y') ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Unsubscribed</span>
                        <span class="text-ink-800">{{ $subscriber->unsubscribed_at?->format('d M Y') ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Last activity</span>
                        <span class="text-ink-800">{{ $subscriber->last_activity_at?->diffForHumans() ?? 'None' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Bounces</span>
                        <span class="{{ $subscriber->bounce_count > 0 ? 'font-semibold text-amber-600' : 'text-ink-800' }}">
                            {{ number_format((int) $subscriber->bounce_count) }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Lists --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Lists</h3>
                    <span class="text-xs text-ink-500">{{ $subscriber->lists->count() }}</span>
                </div>

                @if ($subscriber->lists->isEmpty())
                    <x-empty-state title="Not on any list"
                                   message="Add this contact to a list so campaigns can reach them.">
                        <x-slot name="action">
                            @permission('contacts.update')
                                <a href="{{ route('subscribers.edit', $subscriber) }}" class="kn-btn-secondary">Add to a list</a>
                            @endpermission
                        </x-slot>
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-ink-100">
                        @foreach ($subscriber->lists as $list)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                @permission('contacts.view')
                                    <a href="{{ route('lists.show', $list) }}"
                                       class="min-w-0 truncate text-sm font-medium text-ink-800 hover:text-brand-600">{{ $list->name }}</a>
                                @endpermission

                                @permission('contacts.update')
                                    <x-confirm-form :action="route('lists.detach', ['list' => $list, 'subscriber' => $subscriber])"
                                                    label="Remove"
                                                    button-class="kn-btn-ghost kn-btn-sm text-red-600"
                                                    :message="'Remove this contact from '.$list->name.'?'" />
                                @endpermission
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Tags --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Tags</h3>
                    <span class="text-xs text-ink-500">{{ $subscriber->tags->count() }}</span>
                </div>

                @if ($subscriber->tags->isEmpty())
                    <x-empty-state title="No tags"
                                   message="Tags make this contact easy to find and segment later.">
                        <x-slot name="action">
                            @permission('contacts.update')
                                <a href="{{ route('subscribers.edit', $subscriber) }}" class="kn-btn-secondary">Add tags</a>
                            @endpermission
                        </x-slot>
                    </x-empty-state>
                @else
                    <div class="flex flex-wrap gap-2 p-5">
                        @foreach ($subscriber->tags as $tag)
                            @permission('contacts.view')
                                <a href="{{ route('tags.show', $tag) }}" class="kn-badge ring-1 ring-inset"
                                   style="background-color: {{ $swatch($tag->color) }}1a; color: {{ $swatch($tag->color) }}; --tw-ring-color: {{ $swatch($tag->color) }}33">
                                    {{ $tag->name }}
                                </a>
                            @endpermission
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Consent --}}
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Consent</h3></div>
                <div class="space-y-3 p-5 text-sm">
                    <div>
                        <p class="kn-stat-label">Consent status</p>
                        <p class="mt-0.5 text-ink-800">{{ $consentLabels[$subscriber->consent_status] ?? ucfirst((string) $subscriber->consent_status) }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Recorded</span>
                        <span class="text-ink-800">{{ $subscriber->consent_at?->format('d M Y H:i') ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">IP address</span>
                        <span class="font-mono text-xs text-ink-800">{{ $subscriber->consent_ip ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink-500">Source</span>
                        <span class="text-ink-800">{{ $subscriber->source ?: '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
