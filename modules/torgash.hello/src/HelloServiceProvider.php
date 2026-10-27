<?php

namespace Torgash\Hello;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HelloServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(dirname(__DIR__).'/routes.php');

        // Demonstrates a filter hook: any module can transform the greeting.
        hook_listen('hello.greeting', fn (string $value) => $value.' · via HookBus');
    }
}
