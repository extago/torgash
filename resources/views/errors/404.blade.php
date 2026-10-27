@include('errors.frame', ['code' => 404, 'title' => __('Page not found'), 'text' => __('The address may have changed or the page was removed.'), 'actions' => ['search', 'home', 'back']])
