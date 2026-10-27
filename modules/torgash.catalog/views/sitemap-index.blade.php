<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
{{-- What /sitemap.xml becomes once the catalogue outgrows one file: a list of
     the files rather than a list of addresses. Nothing else changes address,
     which is what makes this safe to start doing on an existing shop. --}}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@for ($page = 1; $page <= $pages; $page++)
    <sitemap>
        <loc>{{ route('catalog.sitemap.page', $page) }}</loc>
    </sitemap>
@endfor
</sitemapindex>
