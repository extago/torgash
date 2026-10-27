<?php

namespace Torgash\Pages;

use App\Core\Seo\RedirectManager;
use RuntimeException;
use Torgash\Pages\Models\Page;

/**
 * Addresses in a tree: computing them, keeping descendants in step, and
 * writing the 301 every move leaves behind.
 *
 * All of it lives here rather than in the admin controller, because the
 * controller is not the only thing that saves a page — an import, a seeder and
 * a test all do — and a page whose `path` was written by one of those and not
 * by the other would be unreachable at its own address.
 *
 * The recursion is deliberate and shallow: a section is a handful of pages,
 * and each descendant is saved through the model so that it writes its OWN
 * redirect. Renaming "info" to "help" therefore leaves /info → /help and
 * /info/delivery → /help/delivery, which is the whole reason `path` is a
 * column instead of something assembled at read time.
 */
class PageTree
{
    /** The full address a page should have, given its parent and its slug. */
    public static function pathFor(Page $page): string
    {
        $slug = trim((string) $page->slug, '/');

        if (! $page->parent_id) {
            return $slug;
        }

        // The parent as it is STORED: a parent saved earlier in the same
        // request has already had its own path recomputed, and reading the
        // relation would hand back whatever was loaded before that.
        $parentPath = Page::where('id', $page->parent_id)->value('path');

        return $parentPath ? trim($parentPath, '/').'/'.$slug : $slug;
    }

    /**
     * Re-address every descendant of a page whose own path just moved. Each
     * child is saved individually, so each writes its own redirect and its own
     * children follow through the same event.
     */
    public static function syncDescendants(Page $page): void
    {
        foreach (Page::where('parent_id', $page->id)->get() as $child) {
            // Touching the model is enough: the saving hook recomputes the
            // path from the (already updated) parent.
            $child->save();
        }
    }

    /**
     * Refuses a parent that would make the page its own ancestor. Checked on
     * the way in rather than trusted: a cycle here is not a bad screen, it is
     * a request that never returns.
     */
    public static function assertNoCycle(?int $pageId, ?int $parentId): void
    {
        if (! $pageId || ! $parentId) {
            return;
        }

        $seen = [];
        $cursor = $parentId;

        while ($cursor) {
            if ($cursor === $pageId) {
                throw new RuntimeException(__('A page cannot be inside itself.'));
            }

            if (isset($seen[$cursor])) {
                return; // a cycle that predates this save; not ours to loop on
            }

            $seen[$cursor] = true;
            $cursor = Page::where('id', $cursor)->value('parent_id');
        }
    }

    public static function redirects(): RedirectManager
    {
        return app(RedirectManager::class);
    }
}
