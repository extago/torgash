{{-- What is under this category, as links.

     The grid below already holds everything in the subtree, so this is not a
     way to reach products that are otherwise hidden — it is how a visitor
     NARROWS, and how a crawler learns that the second level of the catalogue
     exists at all. Before this, it did not appear on any page. --}}
@if ($subcategories->isNotEmpty())
    <div class="bg-base-100 border border-base-300 rounded-box p-3 flex flex-wrap gap-2">
        @foreach ($subcategories as $child)
            <a href="{{ route('catalog.category', $child->slug) }}" class="btn btn-sm btn-ghost normal-case gap-1.5">
                {{ $child->t('name') }}
                <x-icon name="chevron-right" size="sm" class="opacity-40" />
            </a>
        @endforeach
    </div>
@endif
