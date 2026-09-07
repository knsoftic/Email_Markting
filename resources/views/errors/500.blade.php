@include('errors.layout', [
    'code' => 500,
    'title' => 'Something went wrong at our end',
    'message' => 'This is our fault, not yours. It has been recorded and nothing you were working on was lost.',
])
