<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// FrankenPHP's worker mode runs this same file as a worker (`worker index.php`
// in the Caddyfile): boot once, then serve request after request.
if (App\Core\Support\FrankenPhpWorker::wanted()) {
    App\Core\Support\FrankenPhpWorker::run(dirname(__DIR__));

    return;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
