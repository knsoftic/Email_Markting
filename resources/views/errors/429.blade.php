@include('errors.layout', [
    'code' => 429,
    'title' => 'Too many attempts',
    'message' => 'You have tried that more times than we allow in a short period. Wait a minute and try again.',
    'showBack' => false,
])
