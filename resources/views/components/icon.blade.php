@props(['name', 'size' => 'md'])
{{--
    Lucide (ISC), vendored in resources/icons/lucide/ by tools/vendor-lucide.php.
    Any of the ~2000 icons works by its lucide.dev name — a module picks what it
    needs without the core keeping a list. Look names up at lucide.dev/icons.

        <x-icon name="shopping-bag" />
        <x-icon name="trash-2" size="sm" />
        <x-icon name="user" class="text-primary" />

    Prefer a size preset over a bare height class: it keeps icons on four steps
    instead of drifting into a dozen ad-hoc ones.
--}}
@php
    $sizes = ['sm' => 'h-3.5 w-3.5', 'md' => 'h-4 w-4', 'lg' => 'h-5 w-5', 'xl' => 'h-6 w-6'];
    $markup = \App\Core\Support\Icons::markup($name);

    // Loud in dev, invisible in production: a typo in some module's manifest
    // should not take the whole admin down on a live store.
    if ($markup === null && config('app.debug')) {
        throw \App\Core\Support\Icons::missing($name);
    }

    // An explicit h-*/w-* in the caller's class wins outright: emitting both it
    // and the preset would leave the winner up to CSS source order.
    $sized = preg_match('/(^|\s)[hw]-/', (string) $attributes->get('class'));

    $attrs = $attributes
        ->class(['lucide', $sized ? '' : ($sizes[$size] ?? $sizes['md'])])
        ->merge(['aria-hidden' => 'true']);
@endphp
@if ($markup !== null)
    {{-- Every Lucide file opens with the same tag (tools/vendor-lucide.php);
         its stroke attributes live in app.css as `svg.lucide`, so a page of
         four hundred icons does not repeat them four hundred times. --}}
    {!! '<svg viewBox="0 0 24 24" '.$attrs->toHtml().'>'.substr($markup, strpos($markup, '>') + 1) !!}
@endif
