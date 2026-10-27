<?php

namespace App\Http\Controllers;

use App\Core\Seo\LlmsMap;
use App\Core\Seo\Robots;
use Illuminate\Http\Response;

/**
 * The two files at the root that are addressed to programs: robots.txt (the
 * rules) and llms.txt (the map). Both are composed per storefront, which is
 * why neither can be a file in public/ any more.
 */
class WellKnownController extends Controller
{
    public function robots(): Response
    {
        return $this->text(Robots::render());
    }

    public function llms(): Response
    {
        // Nobody is invited: there is no map. The alternative — publishing a
        // guide to the shop for readers robots.txt has just turned away —
        // is the shop saying two different things at the same address.
        abort_unless(Robots::invitesAgents(), 404);

        return $this->text(LlmsMap::render());
    }

    /**
     * `text/plain`, deliberately, for the Markdown ones too: the address
     * already says `.md`, every agent reads the bytes, and the honest label
     * (`text/markdown`) makes a browser DOWNLOAD the file instead of showing
     * it — which is the one thing a shop owner checking their own map wants
     * to do.
     */
    protected function text(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
