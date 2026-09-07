@php
    /**
     * Sidebar definition for the KN Softic app menu.
     *
     * An item only renders when its route actually exists AND the user holds
     * the permission. That keeps the "no dummy buttons" rule enforceable while
     * the platform is being built out phase by phase: as each module's routes
     * land, its menu entry appears on its own.
     */
    $icons = [
        'dashboard' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
        'contacts' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'campaign' => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-3.685m4.102-.976c3.14.257 6.096 1.093 8.777 2.376a23.02 23.02 0 0 0 .348-5.834M10.34 6.66a23.02 23.02 0 0 0 8.777-2.376 23.018 23.018 0 0 1 .348 5.834m0 0a3 3 0 0 1 0 5.716',
        'inbox' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
        'mailbox' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        'template' => 'M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15',
        'automation' => 'M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122',
        'smtp' => 'M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418',
        'analytics' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        'logs' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'billing' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
        'bell' => 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
        'settings' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456c.516-.193 1.096.026 1.37.518l1.296 2.247c.275.492.16 1.11-.27 1.47l-.972.81c-.29.24-.437.613-.43.99a6.93 6.93 0 0 1 0 .255c-.007.378.14.75.43.99l.971.811c.43.36.545.978.27 1.47l-1.295 2.247a1.125 1.125 0 0 1-1.37.518l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.518l-1.297-2.247a1.125 1.125 0 0 1 .271-1.47l.971-.811c.29-.24.436-.613.43-.99a6.932 6.932 0 0 1 0-.255c.006-.378-.14-.75-.43-.99l-.97-.811a1.125 1.125 0 0 1-.272-1.47l1.297-2.247a1.125 1.125 0 0 1 1.37-.518l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'admin' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 0c-2.485 0-4.5 1.79-4.5 4v.75A8.96 8.96 0 0 0 12 21a8.96 8.96 0 0 0 4.5-1.25V16c0-2.21-2.015-4-4.5-4Z',
    ];

    $sections = [
        [
            'label' => null,
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Contacts',
            'items' => [
                ['label' => 'Subscribers', 'route' => 'subscribers.index', 'icon' => 'contacts', 'permission' => 'contacts.view'],
                ['label' => 'Lists', 'route' => 'lists.index', 'permission' => 'contacts.view'],
                ['label' => 'Tags', 'route' => 'tags.index', 'permission' => 'contacts.view'],
                ['label' => 'Segments', 'route' => 'segments.index', 'permission' => 'contacts.view'],
                ['label' => 'Import', 'route' => 'imports.index', 'permission' => 'contacts.import'],
                ['label' => 'Suppression List', 'route' => 'suppressions.index', 'permission' => 'contacts.view'],
            ],
        ],
        [
            'label' => 'Campaigns',
            'items' => [
                ['label' => 'All Campaigns', 'route' => 'campaigns.index', 'icon' => 'campaign', 'permission' => 'campaigns.view'],
                ['label' => 'Create Campaign', 'route' => 'campaigns.create', 'permission' => 'campaigns.create'],
                ['label' => 'Scheduled', 'route' => 'campaigns.scheduled', 'permission' => 'campaigns.view'],
                ['label' => 'Campaign Replies', 'route' => 'campaign-replies.index', 'permission' => 'inbox.view'],
            ],
        ],
        [
            'label' => 'Inbox',
            'items' => [
                ['label' => 'Inbox', 'route' => 'inbox.index', 'icon' => 'inbox', 'permission' => 'inbox.view'],
                ['label' => 'Sent', 'route' => 'inbox.sent', 'permission' => 'inbox.view'],
                ['label' => 'Drafts', 'route' => 'inbox.drafts', 'permission' => 'inbox.view'],
                ['label' => 'Starred', 'route' => 'inbox.starred', 'permission' => 'inbox.view'],
                ['label' => 'Spam', 'route' => 'inbox.spam', 'permission' => 'inbox.view'],
                ['label' => 'Trash', 'route' => 'inbox.trash', 'permission' => 'inbox.view'],
            ],
        ],
        [
            'label' => 'Sending',
            'items' => [
                ['label' => 'Mailboxes', 'route' => 'mailboxes.index', 'icon' => 'mailbox', 'permission' => 'mailboxes.view'],
                ['label' => 'SMTP Accounts', 'route' => 'smtp.index', 'icon' => 'smtp', 'permission' => 'smtp.view'],
                ['label' => 'Templates', 'route' => 'templates.index', 'icon' => 'template', 'permission' => 'templates.view'],
                ['label' => 'Automations', 'route' => 'automations.index', 'icon' => 'automation', 'permission' => 'automation.view'],
            ],
        ],
        [
            'label' => 'Insights',
            'items' => [
                ['label' => 'Analytics', 'route' => 'analytics.index', 'icon' => 'analytics', 'permission' => 'analytics.view'],
                ['label' => 'Email Logs', 'route' => 'logs.index', 'icon' => 'logs', 'permission' => 'logs.view'],
                ['label' => 'Activity', 'route' => 'activity.index', 'icon' => 'logs', 'permission' => 'settings.view'],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                ['label' => 'Plan & Billing', 'route' => 'billing.index', 'icon' => 'billing'],
                ['label' => 'Notifications', 'route' => 'notifications.index', 'icon' => 'bell'],
                ['label' => 'Team', 'route' => 'team.index', 'icon' => 'contacts', 'permission' => 'team.manage'],
                ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'settings', 'permission' => 'settings.view'],
                ['label' => 'Profile', 'route' => 'profile.edit', 'icon' => 'settings'],
            ],
        ],
    ];

    $user = auth()->user();

    // A super admin has no account of their own, so tenant modules are hidden
    // for them entirely rather than shown and then redirected away.
    $hasAccount = $user?->account_id !== null;
    $alwaysVisible = ['dashboard', 'profile.edit'];

    $visible = static function (array $item) use ($user, $hasAccount, $alwaysVisible): bool {
        if (! \Illuminate\Support\Facades\Route::has($item['route'])) {
            return false;
        }

        if (! $hasAccount && ! in_array($item['route'], $alwaysVisible, true)) {
            return false;
        }

        return ! isset($item['permission']) || $user?->hasPermission($item['permission']);
    };
@endphp

<nav class="flex h-full flex-col">
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-white/10 px-5">
        <a href="{{ route('dashboard') }}" class="flex items-center">
            <x-application-logo :inverted="true" />
        </a>
    </div>

    <div class="flex-1 overflow-y-auto px-3 pb-6">
        @superadmin
            <div class="kn-nav-section">Platform</div>
            @if (\Illuminate\Support\Facades\Route::has('admin.dashboard'))
                <a href="{{ route('admin.dashboard') }}"
                   class="kn-nav-link {{ request()->routeIs('admin.*') ? 'kn-nav-link-active' : '' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['admin'] }}"/>
                    </svg>
                    <span>Super Admin</span>
                </a>
            @endif
        @endsuperadmin

        @foreach ($sections as $section)
            @php $items = array_filter($section['items'], $visible); @endphp

            @if (count($items))
                @if ($section['label'])
                    <div class="kn-nav-section">{{ $section['label'] }}</div>
                @else
                    <div class="pt-4"></div>
                @endif

                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}"
                       class="kn-nav-link {{ request()->routeIs($item['route']) ? 'kn-nav-link-active' : '' }}">
                        @if (isset($item['icon']))
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}"/>
                            </svg>
                        @else
                            <span class="ml-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-50"></span>
                            <span class="w-2"></span>
                        @endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endif
        @endforeach
    </div>

    <div class="border-t border-white/10 p-4 text-[11px] leading-relaxed text-ink-500">
        {{ $brand['footer_text'] }}
    </div>
</nav>
