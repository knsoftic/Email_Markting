@include('errors.layout', [
    'code' => 503,
    'title' => 'Down for maintenance',
    'message' => 'We are updating the application. This normally takes a couple of minutes.',
    'showBack' => false,
])
