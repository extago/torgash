{{-- "Search the site / the catalogue", inside the search form (the theme's
     storefront.search.controls). Radios, so the choice is submitted with the
     query and works with no JavaScript; the script re-asks the window when it
     changes and puts the chosen placeholder in the box. Positioned left of
     the theme's submit button; the box's own padding is widened for it
     in script.blade.php. --}}
{{-- The menu is a popover (core's component): it stays INSIDE this form
     in the DOM, so the radios are submitted with the query, while the
     browser draws it in the top layer, over the live window. --}}
<div class="torgash-search-scope absolute right-11 top-1/2 -translate-y-1/2">
    <x-popover class="btn btn-ghost btn-square btn-sm opacity-70 hover:opacity-100" panel-class="p-2 w-52" :label="__('Where to search')" :title="__('Where to search')">
        <ul class="menu w-full p-0">
            @foreach ([
                \Torgash\Search\Scope::CATALOG => ['icon' => 'layout-grid', 'label' => __('In the catalogue'), 'placeholder' => __('Search the catalogue')],
                \Torgash\Search\Scope::SITE => ['icon' => 'layout-template', 'label' => __('Across the site'), 'placeholder' => __('Search the site')],
            ] as $value => $option)
                <li>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="scope" value="{{ $value }}" class="peer sr-only" data-placeholder="{{ $option['placeholder'] }}" @checked($scope === $value)>
                        <x-icon name="{{ $option['icon'] }}" size="sm" class="opacity-60" />
                        <span class="flex-1">{{ $option['label'] }}</span>
                        <x-icon name="check" size="sm" class="invisible peer-checked:visible" />
                    </label>
                </li>
            @endforeach
        </ul>

        <x-slot:trigger>
            <x-icon name="sliders-horizontal" size="sm" />
        </x-slot:trigger>
    </x-popover>
</div>
