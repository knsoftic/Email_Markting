{{-- The one people meet most and understand least: the page was left open long
     enough for the session to expire, so the form's token no longer matches. --}}
@include('errors.layout', [
    'code' => 419,
    'title' => 'This page expired',
    'message' => 'It was open long enough for the session to end. Sign in again and your work will still be there.',
    'showBack' => false,
])
