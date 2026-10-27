<?php

use Illuminate\Support\Facades\Route;

Route::get('/hello', function () {
    return view('torgash.hello::hello', [
        'greeting' => hook_filter('hello.greeting', setting('torgash.hello', 'greeting')),
    ]);
})->name('hello');
