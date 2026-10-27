@include('errors.frame', ['code' => 429, 'title' => __('Too many requests'), 'text' => __('Please wait a minute and try again.'), 'actions' => ['retry', 'home']])
