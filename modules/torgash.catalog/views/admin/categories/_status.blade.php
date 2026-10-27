{{-- One row's status, switched in place — the product list's own control.
     Answered by setStatus() with this same cell, plus the row's name out of
     band, since a category that is not listed is drawn dimmed. --}}
@php
    $statusBadge = match ($category->status) {
        \Torgash\Catalog\Models\Category::STATUS_ACTIVE => 'badge-success',
        \Torgash\Catalog\Models\Category::STATUS_HIDDEN => 'badge-warning', // solid, as the products list: a soft warning is too pale to read in the light theme
        default => 'badge-ghost',
    };
@endphp
<td id="category-status-{{ $category->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge {{ $statusBadge }} badge-sm gap-1 cursor-pointer" title="{{ __('Change status') }}">
            {{ $category->statusLabel() }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach (\Torgash\Catalog\Models\Category::statusLabels() as $statusValue => $statusText)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.catalog.categories.status', $category) }}"
                            hx-vals='@json(['status' => $statusValue])'
                            hx-target="#category-status-{{ $category->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => $category->status === $statusValue])>
                        {{ $statusText }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
@if ($oob ?? false)
    <a id="category-name-{{ $category->id }}" hx-swap-oob="true" href="{{ route('admin.catalog.categories.edit', $category) }}"
       @class(['font-medium link link-hover', 'opacity-60' => ! $category->isListed()])>{{ $category->t('name', admin_content_locale()) ?: '—' }}</a>
@endif
