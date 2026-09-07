@extends('public.layout', [
    'heading' => 'You have been unsubscribed',
    'subheading' => $subscriber->email,
])

@section('title', 'Unsubscribed')

@section('content')
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        <p class="font-medium">Done.</p>
        <p class="mt-1">
            {{ $account->name }} will not send any more marketing email to
            <span class="font-medium">{{ $subscriber->email }}</span>.
        </p>
    </div>

    <p class="mt-4 text-sm leading-relaxed text-ink-600">
        This address has been added to their do-not-send list, so it stays off future campaigns even if it is
        imported again.
    </p>

    <p class="mt-3 text-sm leading-relaxed text-ink-600">
        You may still receive non-marketing messages such as receipts or replies to something you sent.
    </p>
@endsection
