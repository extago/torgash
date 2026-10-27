{{-- One corner notice ("added to your wishlist", "added to your basket") for
     an HTMX ANSWER: it arrives out of band into the stack core prints on every
     storefront page (components/notices), newest on top, and leaves by itself
     once its bar has run out; pointing at it or focusing it holds it.

         x-notice with :title, :text (the product's name), :image (a URL),
         :href + :action (where to go next, and the words for it)

     `image` falls back to `icon`, coloured by `iconClass`. A page drawn
     without HTMX never shows one:
     there the page itself already says what changed. --}}
@props(['title', 'text' => null, 'image' => null, 'icon' => 'check', 'iconClass' => 'text-success', 'href' => null, 'action' => null])
<div id="torgash-notices" hx-swap-oob="afterbegin">
    <div class="torgash-notice" role="status" data-notice>
        <span class="torgash-notice-media">
            @if ($image)
                <img src="{{ $image }}" alt="" class="object-contain w-full h-full">
            @else
                <x-icon :name="$icon" size="xl" :class="$iconClass" />
            @endif
        </span>
        <span class="flex flex-col gap-0.5 min-w-0">
            <span class="font-semibold leading-snug">{{ $title }}</span>
            @if ($text)
                <span class="text-sm opacity-70 leading-snug line-clamp-3">{{ $text }}</span>
            @endif
            @if ($href && $action)
                <a href="{{ $href }}" class="link link-primary link-hover text-sm font-medium mt-1 w-fit">{{ $action }}</a>
            @endif
        </span>
        <button type="button" class="btn btn-ghost btn-sm btn-circle btn-touch -mt-1.5 -mr-1.5" data-notice-close aria-label="{{ __('Close') }}">
            <x-icon name="x" size="lg" />
        </button>
        <span class="torgash-notice-life" aria-hidden="true"></span>
    </div>
</div>
