{{--
    The head of a storefront page, printed in one place.

    A theme calls this instead of writing <title>, description, canonical,
    hreflang and OpenGraph by hand; everything it prints comes from
    App\Core\Seo\PageMeta, which the controllers filled in. The `core.head`
    slot at the end is where a module puts a tag of its own (an analytics
    counter, a verification code) without patching a theme.

    A theme MAY override this file like any other core view — but then it owns
    keeping the pieces consistent, which is exactly what having one of them is
    for.
--}}
@php
    $meta = page_meta();
    // The section is the fallback, not the source: views that still say
    // @section('title') keep working, and a controller that filled PageMeta in
    // wins over them.
    $meta->set('title', trim(strip_tags($__env->yieldContent('title'))));
    $alternates = $meta->alternates();
    $image = $meta->image();
@endphp
<title>{{ $meta->title() }}</title>

@if ($meta->description())
    <meta name="description" content="{{ $meta->description() }}">
@endif

@if ($robots = $meta->robots())
    <meta name="robots" content="{{ $robots }}">
@endif

<link rel="canonical" href="{{ $meta->canonical() }}">

@foreach ($alternates as $locale => $url)
    <link rel="alternate" hreflang="{{ str_replace('_', '-', $locale) }}" href="{{ $url }}">
@endforeach
@if ($alternates && ($default = $meta->defaultLocale()) && isset($alternates[$default]))
    <link rel="alternate" hreflang="x-default" href="{{ $alternates[$default] }}">
@endif

<meta property="og:type" content="{{ $meta->type() }}">
<meta property="og:title" content="{{ $meta->title() }}">
<meta property="og:url" content="{{ $meta->canonical() }}">
@if ($meta->siteName())
    <meta property="og:site_name" content="{{ $meta->siteName() }}">
@endif
@if ($meta->description())
    <meta property="og:description" content="{{ $meta->description() }}">
@endif
@if ($image)
    <meta property="og:image" content="{{ $image }}">
@endif
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
@foreach ($alternates as $locale => $url)
    @continue($locale === app()->getLocale())
    <meta property="og:locale:alternate" content="{{ str_replace('-', '_', $locale) }}">
@endforeach

{{-- Twitter reads the OpenGraph tags for everything but the card size, so
     only what it cannot get from them is repeated. --}}
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">

@foreach ($meta->metaTags() as $tag)
    <meta {{ $tag['property'] ? 'property' : 'name' }}="{{ $tag['name'] }}" content="{{ $tag['content'] }}">
@endforeach

@foreach ($meta->linkTags() as $tag)
    <link {!! collect($tag)->map(fn ($value, $attribute) => $attribute.'="'.e($value).'"')->implode(' ') !!}>
@endforeach

{!! structured_data()->render() !!}

{!! hook_render('core.head', ['meta' => $meta]) !!}
