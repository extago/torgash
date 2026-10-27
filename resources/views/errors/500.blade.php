@include('errors.frame', ['code' => 500, 'title' => __('Something went wrong'), 'text' => __('Please try again in a minute.'), 'actions' => ['retry', 'home']])
