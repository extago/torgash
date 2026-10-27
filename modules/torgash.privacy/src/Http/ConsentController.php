<?php

namespace Torgash\Privacy\Http;

use App\Core\Privacy\Consent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Privacy\ConsentState;

/**
 * The visitor's answer from the banner (PLAN §49): all, only the necessary, or
 * a chosen few. Remembered in a cookie for a year (the longest the EU
 * regulators accept before asking again), and written into the record of
 * consents. The banner posts it over fetch and switches the code on in place;
 * without a script it is an ordinary form and the page comes back.
 */
class ConsentController extends Controller
{
    public function __invoke(Request $request)
    {
        $optional = array_values(array_diff(array_keys(Consent::CATEGORIES), [Consent::NECESSARY]));

        $categories = match ($request->input('choice')) {
            'all' => $optional,
            'necessary' => [],
            default => array_values(array_intersect($optional, (array) $request->input('categories', []))),
        };

        app(ConsentState::class)->remember($categories);

        Consent::given([
            'kind' => 'cookies',
            'categories' => $categories,
        ]);

        $cookie = cookie(ConsentState::COOKIE, ConsentState::encode($categories), 60 * 24 * 365);

        return ($request->expectsJson()
            ? response()->json(['categories' => $categories])
            : back())->withCookie($cookie);
    }
}
