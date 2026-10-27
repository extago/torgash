{{-- Auto-generated content_type="categories" — the viewed product's own categories, linked. --}}
<ul class="flex flex-col gap-1">
    @foreach ($categories as $category)
        <li>
            <a href="{{ route('catalog.category', $category->slug) }}" class="link link-hover">
                {{ $category->t('name') }}
            </a>
        </li>
    @endforeach
</ul>
