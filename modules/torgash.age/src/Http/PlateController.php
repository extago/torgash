<?php

namespace Torgash\Age\Http;

/**
 * The plate a covered product shows instead of its photo: "18+" on grey.
 *
 * An address rather than a data: URI, because the plate also stands where a
 * search engine or a messenger's preview reads the page's picture (og:image,
 * the structured data), and those take only an address. Drawn here for any
 * age, kept by the browser for a year (the address carries a version).
 */
class PlateController
{
    public function __invoke(int $age)
    {
        $age = max(1, min(99, $age));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">'
            .'<rect width="800" height="800" fill="#e5e7eb"/>'
            .'<circle cx="400" cy="400" r="190" fill="none" stroke="#9ca3af" stroke-width="24"/>'
            .'<text x="400" y="400" fill="#4b5563" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif" font-size="150" font-weight="700" text-anchor="middle" dominant-baseline="central">'.$age.'+</text>'
            .'</svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
