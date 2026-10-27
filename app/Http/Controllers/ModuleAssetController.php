<?php

namespace App\Http\Controllers;

use App\Core\Modules\ModuleManager;
use Illuminate\Http\Request;
use App\Core\Assets\AssetVersions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves files from an enabled module's assets/ directory.
 *
 * Modules are never copied into public/, so a module that ships JS or CSS
 * needs one well-known URL to reach it: /modules/<id>/assets/<path>. Only
 * enabled modules are served, and the resolved path must stay inside the
 * module's own assets directory.
 */
class ModuleAssetController extends Controller
{
    /** Extensions worth serving from a module — anything else is a mistake or an attack. */
    public const TYPES = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
    ];

    public function __invoke(Request $request, ModuleManager $modules, string $module, string $path): BinaryFileResponse
    {
        $manifest = $modules->find($module);
        abort_unless($manifest && $modules->isEnabled($module), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(isset(self::TYPES[$extension]), 404);

        $root = realpath($manifest->path.DIRECTORY_SEPARATOR.'assets');
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;

        // realpath() has already collapsed any ../ — reject anything that
        // climbed out of the module's assets directory.
        abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        $response = response()->file($file, [
            'Content-Type' => self::TYPES[$extension],
        ]);

        // A year only for the address naming this exact version (module_asset(),
        // theme_asset()); anything else is asked about each time.
        app(AssetVersions::class)->stamp($response, $file, request()->query('v'));

        // Last-Modified is already on it; a browser revalidating an unchanged
        // file gets an empty 304 instead of the bytes again.
        $response->isNotModified(request());

        return $response;
    }
}
