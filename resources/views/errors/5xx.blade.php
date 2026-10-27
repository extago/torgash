@include('errors.frame', ['code' => $exception->getStatusCode(), 'title' => __('Something went wrong'), 'text' => __('Please try again in a minute.'), 'actions' => ['retry', 'home']])
