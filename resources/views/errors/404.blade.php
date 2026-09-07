@include('errors.layout', [
    'code' => 404,
    'title' => 'That page does not exist',
    'message' => 'The link may be out of date, or the thing it pointed at may have been deleted.',
])
