<x-admin-layout>
    <x-slot name="header">Activity log</x-slot>

    <x-page-header title="Activity log" subtitle="Every recorded action across the platform." />

    <form method="GET" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search description, event or IP" />
            </div>
            <select name="event" class="kn-select">
                <option value="">Any event</option>
                @foreach ($events as $event)
                    <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ $event }}</option>
                @endforeach
            </select>
            <div class="grid grid-cols-2 gap-2">
                <x-text-input name="from" type="date" :value="$filters['from'] ?? ''" />
                <x-text-input name="to" type="date" :value="$filters['to'] ?? ''" />
            </div>
            <button type="submit" class="kn-btn-primary">Filter</button>
        </div>
    </form>

    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead><tr><th>When</th><th>Event</th><th>Description</th><th>User</th><th>Account</th><th>IP</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-xs text-ink-500" title="{{ $log->created_at }}">
                                {{ $log->created_at->format('d M Y H:i') }}
                            </td>
                            <td><span class="kn-badge-gray">{{ $log->event }}</span></td>
                            <td class="text-ink-800">{{ $log->description }}</td>
                            <td>
                                @if ($log->user)
                                    <a href="{{ route('admin.users.show', $log->user) }}" class="text-brand-600 hover:text-brand-700">
                                        {{ $log->user->name }}
                                    </a>
                                @else
                                    <span class="text-ink-400">System</span>
                                @endif
                            </td>
                            <td>
                                @if ($log->account)
                                    <a href="{{ route('admin.accounts.show', $log->account) }}" class="text-brand-600 hover:text-brand-700">
                                        {{ $log->account->name }}
                                    </a>
                                @else
                                    <span class="text-ink-400">Platform</span>
                                @endif
                            </td>
                            <td class="text-xs text-ink-500">{{ $log->ip ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state title="No activity matches these filters" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $logs->links() }}</div>
        @endif
    </div>
</x-admin-layout>
