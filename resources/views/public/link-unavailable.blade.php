@extends('public.layout', ['heading' => 'That link is no longer available'])

@section('title', 'Link unavailable')

@section('content')
    {{--
        Shown when a tracked link cannot be resolved: the campaign was deleted,
        the link row went with it, or the ids in the URL do not belong together.

        It deliberately does not say which — a page that distinguishes "no such
        recipient" from "no such link" is a way to probe for valid ids. It also
        does not guess a destination: sending someone somewhere we cannot
        verify is exactly the open redirect the tracking route is built to
        avoid.
    --}}
    <p class="text-sm text-ink-700">
        The link you followed points at a campaign that is no longer here, so we cannot send you on
        to where it was going.
    </p>

    <p class="mt-3 text-sm text-ink-600">
        Nothing has happened to your subscription — this page is only about the link. If you were
        trying to reach something specific, the sender's own website is the best place to look, or
        you can reply to the email you clicked from.
    </p>
@endsection
