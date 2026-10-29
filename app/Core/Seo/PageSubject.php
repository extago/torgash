<?php

namespace App\Core\Seo;

/**
 * A record that a storefront page is ABOUT — a product, a category, a brand, a
 * text page — and which therefore has an opinion about its own head.
 *
 * The interface is core's while every field it names belongs to a module,
 * because two different modules have to read those fields without knowing each
 * other: `torgash.seo` fills the empty ones from a template and lists them on
 * its audit screen, and the page itself prints them. Before this, the pages
 * module called its column `page_title` and the catalogue would have called its
 * own `meta_title`, and anything reading both would have been a table of
 * special cases.
 *
 * What it deliberately is NOT is a place to store them. The columns stay on the
 * entity (PLAN §30.3): a product's meta description is a property of the
 * product, it has to survive `torgash.seo` being uninstalled, and it has to
 * reach an export and the API without a line of extra code.
 */
interface PageSubject
{
    /**
     * What this record says about its own page, in the CURRENT locale.
     *
     * Absent keys and empty values both mean "not stated" — PageMeta::set()
     * ignores them, which is what lets a half-filled record keep whatever the
     * controller worked out.
     *
     * @return array{title?: ?string, description?: ?string, h1?: ?string, noindex?: bool, canonical?: ?string, image?: ?string}
     */
    public function pageMeta(): array;

    /** The word this kind of record is known by in an SEO template: product, category, brand, page. */
    public function pageMetaKind(): string;
}
