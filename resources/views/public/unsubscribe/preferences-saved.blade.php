@extends('public.layout', [
    'heading' => $optedOut ? 'You are unsubscribed' : 'Your preferences are saved',
    'subheading' => $subscriber->email,
])

@section('title', $optedOut ? 'Unsubscribed' : 'Preferences saved')

@section('content')
    @if ($optedOut)
        {{-- Unticking every list is a full opt-out, and it was recorded as
             one — so this page says exactly that rather than "saved". --}}
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-medium">Done — that stops everything.</p>
            <p class="mt-1">
                You left every list, so {{ $account->name }} will not send marketing email to
                <span class="font-medium">{{ $subscriber->email }}</span> any more. It takes effect immediately.
            </p>
        </div>
    @else
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-medium">Saved.</p>
            <p class="mt-1">
                @if ($left > 0)
                    You left {{ $left }} {{ \Illuminate\Support\Str::plural('list', $left) }} and are staying on
                    {{ $kept }}.
                @else
                    Nothing changed — you are still on all {{ $kept }} of your
                    {{ \Illuminate\Support\Str::plural('list', $kept) }}.
                @endif
            </p>
        </div>

        <p class="mt-4 text-sm leading-relaxed text-ink-600">
            You can change this again from the preferences link at the bottom of any email
            {{ $account->name }} sends you.
        </p>
    @endif
@endsection
