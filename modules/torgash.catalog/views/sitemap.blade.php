<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
{{-- The two extra namespaces are declared unconditionally: a crawler reads the
     root element once, and a conditional namespace is one more thing to be
     wrong about on the day a shop adds its first photograph. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
        @if (isset($url['lastmod']))
        <lastmod>{{ $url['lastmod'] }}</lastmod>
        @endif
        @isset($url['changefreq'])
        <changefreq>{{ $url['changefreq'] }}</changefreq>
        @endisset
        @isset($url['priority'])
        <priority>{{ $url['priority'] }}</priority>
        @endisset
        @foreach ($url['alternates'] ?? [] as $locale => $href)
        <xhtml:link rel="alternate" hreflang="{{ str_replace('_', '-', $locale) }}" href="{{ $href }}"/>
        @endforeach
        @foreach ($url['images'] ?? [] as $image)
        <image:image>
            <image:loc>{{ $image['loc'] }}</image:loc>
            <image:title>{{ $image['title'] }}</image:title>
        </image:image>
        @endforeach
    </url>
@endforeach
</urlset>
