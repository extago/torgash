<?php

namespace Torgash\Pages\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Pages\Models\Page;

/**
 * Its part of the demo shop (PLAN §55.3): the pages a shop is expected to
 * have — about, delivery, payment, returns, contacts and a privacy policy
 * that says plainly it is a placeholder. The contacts page is made of the
 * storefront's placeholders, so it shows whatever the shop set.
 *
 * Read from `database/demo/pages/pages.json`. A slug the shop already uses
 * is left to the shop's own page.
 */
class PagesDemo
{
    public function install(DemoPack $pack): void
    {
        $made = 0;

        foreach ($pack->json('pages/pages') as $position => $row) {
            if (Page::where('slug', $row['slug'])->whereNull('parent_id')->exists()) {
                continue;
            }

            Page::create([
                'slug' => $row['slug'],
                'title' => $row['title'],
                'body' => $row['body'],
                'enabled' => true,
                'in_sitemap' => $row['slug'] !== 'privacy',
                'position' => $position,
            ]);
            $made++;
        }

        $pack->say($made.' pages.');
    }
}
