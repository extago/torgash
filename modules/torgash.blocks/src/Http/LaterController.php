<?php

namespace Torgash\Blocks\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;

/**
 * A block that waited for its tab to be opened or to be scrolled to, drawn for
 * whoever asked (ZoneRenderer). A piece of a page, not a page: nothing here
 * is kept, and what it prints is the asker's own.
 */
class LaterController
{
    public function __invoke(Request $request, Block $block, ZoneRenderer $zones): Response
    {
        abort_unless($block->enabled && $zones->waits($block), 404);

        return response($zones->drawLater($block, $request->query()));
    }
}
