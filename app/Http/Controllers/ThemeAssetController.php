<?php

namespace App\Http\Controllers;

use App\Core\Assets\AssetVersions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves files from a theme's assets/ directory — /themes/<dir>/assets/<path>
 * — the same way ModuleAssetController serves a module's: a preview picture
 * for the Themes screen, a stylesheet, a font. Themes are never copied into
 * public/ either, and before this a theme had no way to ship a file at all.
 *
 * Any theme on disk, not only the active one: the Themes screen shows every
 * theme's preview, and a child theme's page links its parent's fonts.
 */
class ThemeAssetController extends Controller
{
    public function __invoke(string $theme, string $path): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $theme) === 1, 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = ModuleAssetController::TYPES + ['woff' => 'font/woff', 'gif' => 'image/gif', 'avif' => 'image/avif'];
        abort_unless(isset($types[$extension]), 404);

        $root = realpath(config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'assets');
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;

        // realpath() has already collapsed any ../ — anything that climbed out
        // of the theme's own assets directory is not ours to serve.
        abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        $response = response()->file($file, [
            'Content-Type' => $types[$extension],
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
