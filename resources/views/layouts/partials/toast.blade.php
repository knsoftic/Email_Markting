{{--
    Global toast host. Any script can raise one with:
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { type: 'success', message: 'Saved' }
        }));
    Used by the AJAX actions across the app (bulk operations, sync, tests).
--}}
<div x-data="{
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type: detail.type || 'info', message: detail.message || '' });
            setTimeout(() => this.remove(id), detail.timeout || 4000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); }
     }"
     @toast.window="add($event.detail)"
     class="pointer-events-none fixed bottom-5 right-5 z-50 flex w-80 flex-col gap-2">

    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition
             class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-white px-4 py-3 text-sm shadow-panel"
             :class="{
                'border-emerald-200 text-emerald-800': toast.type === 'success',
                'border-red-200 text-red-800': toast.type === 'error',
                'border-amber-200 text-amber-800': toast.type === 'warning',
                'border-ink-200 text-ink-800': toast.type === 'info'
             }">
            <p class="flex-1" x-text="toast.message"></p>
            <button type="button" @click="remove(toast.id)" class="shrink-0 opacity-50 hover:opacity-100" aria-label="Dismiss">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</div>
