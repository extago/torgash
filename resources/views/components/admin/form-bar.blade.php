{{-- The bar on top of an edit screen: the way back, the record's name, its
     tools, and in the `actions` slot the screen's own buttons (Cancel and
     Save). One piece for every form, so a phone gets its own arrangement in
     one place (app.css, "The admin on a phone"): there the bar sticks to the
     top of the screen and the actions stand at its foot, under the thumb.

     The slot is written LAST, after the bar's own content: a named slot
     before the default content leaves an output buffer open. The gutter is
     the page's own (--admin-gutter), so the bar runs edge to edge at every
     width. --}}
<div {{ $attributes->class('admin-form-bar sticky top-16 z-20 -mx-(--admin-gutter) px-(--admin-gutter) py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3') }}>
    {{ $slot }}
    @isset($actions)
        <div {{ $actions->attributes->class('admin-form-bar-actions') }}>{{ $actions }}</div>
    @endisset
</div>
