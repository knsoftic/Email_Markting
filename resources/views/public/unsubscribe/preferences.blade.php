@extends('public.layout', [
    'heading' => 'Your email preferences',
    'subheading' => $subscriber->email,
])

@section('title', 'Email preferences')

@section('content')
    @if ($suppressed)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-medium">You are unsubscribed.</p>
            <p class="mt-1">
                {{ $account->name }} will not send you marketing email at this address. There is nothing left
                to choose here.
            </p>
        </div>
    @elseif ($lists->isEmpty())
        {{--
            On no lists, but not suppressed: a campaign aimed at everyone could
            still reach them. Saying "you receive nothing" would be a promise
            the system does not keep, so the honest offer is the real opt-out.
        --}}
        <p class="text-sm leading-relaxed text-ink-700">
            You are not on any of {{ $account->name }}&rsquo;s mailing lists, so there is nothing to change here.
            You may still receive occasional email sent to all contacts.
        </p>

        <a href="{{ $unsubscribeUrl }}" class="kn-btn-danger mt-6 flex w-full justify-center">
            Unsubscribe from everything
        </a>
    @else
        <p class="text-sm leading-relaxed text-ink-700">
            Choose what you would like to keep receiving from
            <span class="font-medium text-ink-900">{{ $account->name }}</span>. Unticking a list stops that one
            only — everything else carries on as before.
        </p>

        {{-- No Alpine here: the public layout ships CSS only, so an x-data on
             this form would be an attribute that does nothing. The warning
             below is static because it is always true. --}}
        <form method="POST" action="{{ $actionUrl }}" class="mt-5">
            @csrf

            <div class="space-y-2">
                @foreach ($lists as $list)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 transition hover:bg-ink-50">
                        <input type="checkbox" name="lists[]" value="{{ $list->id }}" checked
                               class="kn-checkbox mt-0.5">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-ink-900">{{ $list->name }}</span>
                            @if ($list->description)
                                <span class="mt-0.5 block text-xs text-ink-500">{{ $list->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            {{--
                Unticking everything IS a full opt-out, and it is recorded as
                one. Saying so before the button is pressed is the difference
                between a choice and a surprise.
            --}}
            <p class="mt-4 rounded-lg bg-ink-50 px-4 py-3 text-xs leading-relaxed text-ink-600">
                Leaving every list unticked unsubscribes you from
                {{ $account->name }} entirely, the same as the link below.
            </p>

            <button type="submit" class="kn-btn-primary mt-4 w-full">Save my preferences</button>
        </form>

        <div class="mt-5 border-t border-ink-200/70 pt-4 text-center">
            <a href="{{ $unsubscribeUrl }}" class="text-xs font-medium text-ink-500 underline hover:text-ink-700">
                Or unsubscribe from everything
            </a>
        </div>
    @endif
@endsection
