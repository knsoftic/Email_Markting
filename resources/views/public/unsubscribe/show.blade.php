@extends('public.layout', [
    'heading' => 'Unsubscribe from '.$account->name,
    'subheading' => $subscriber->email,
])

@section('title', 'Unsubscribe')

@section('content')
    @if ($alreadyGone)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-medium">You are already unsubscribed.</p>
            <p class="mt-1">
                {{ $account->name }} will not send you any more marketing email. Nothing further is needed.
            </p>
        </div>
    @else
        <p class="text-sm leading-relaxed text-ink-700">
            You are receiving marketing email from <span class="font-medium text-ink-900">{{ $account->name }}</span>
            @if ($campaign)
                because of the campaign &ldquo;{{ $campaign->name }}&rdquo;.
            @else
                at this address.
            @endif
        </p>

        <p class="mt-3 text-sm leading-relaxed text-ink-700">
            Confirming below stops all marketing email from {{ $account->name }} to
            <span class="font-medium text-ink-900">{{ $subscriber->email }}</span>. It takes effect immediately.
        </p>

        <form method="POST" action="{{ $confirmUrl }}" class="mt-6">
            @csrf
            <button type="submit" class="kn-btn-danger w-full">
                Unsubscribe {{ $subscriber->email }}
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-ink-500">
            Changed your mind? Just close this page — nothing has been changed yet.
        </p>
    @endif
@endsection
