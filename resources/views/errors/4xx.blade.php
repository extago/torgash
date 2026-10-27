@include('errors.frame', ['code' => $exception->getStatusCode(), 'title' => __('This request cannot be answered'), 'text' => __('Please go back and try again.'), 'actions' => ['home', 'back']])
