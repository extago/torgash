<?php

namespace Torgash\Banners\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Torgash\Banners\Clicks;

/**
 * `banners.click` — the beacon a banner's link sends as it is followed.
 * Answers nothing either way: a beacon reads no answer, and an unknown id is
 * simply not counted.
 */
class ClickController extends Controller
{
    public function __invoke(Request $request, Clicks $clicks)
    {
        $id = (int) $request->input('banner');

        if ($id > 0 && $clicks->countable($id)) {
            $clicks->count($id);
        }

        return response()->noContent();
    }
}
