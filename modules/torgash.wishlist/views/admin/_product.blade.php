{{-- One product in a statistics row: photo in the list's own 40px box, name
     and SKU, price and whether it can be bought — the same look as the
     catalogue's list, so a product reads alike on every screen. --}}
<td class="w-14">
    @if ($wishImage = $wishProduct->primaryImageUrl(120))
        <div class="h-10 w-10 shrink-0 rounded bg-base-200 overflow-hidden">
            <img src="{{ $wishImage }}" alt="" class="h-full w-full object-contain" loading="lazy">
        </div>
    @else
        <div class="h-10 w-10 rounded bg-base-200 flex items-center justify-center"><x-icon name="image" class="opacity-30" /></div>
    @endif
</td>
<td class="min-w-0">
    <a href="{{ route('admin.catalog.products.edit', $wishProduct) }}" class="link link-hover font-medium">{{ $wishProduct->t('name', admin_content_locale()) ?: '—' }}</a>
    <div class="font-mono text-xs opacity-50">{{ $wishProduct->sku }}</div>
</td>
<td class="text-end whitespace-nowrap tabular-nums">{{ $wishProduct->adminPrice() }}</td>
<td class="whitespace-nowrap">
    @if ($wishProduct->trashed())
        <span class="badge badge-sm badge-ghost">{{ __('Deleted') }}</span>
    @elseif ($wishProduct->status !== 'active')
        <span class="badge badge-sm badge-ghost">{{ $wishProduct->status === 'hidden' ? __('Hidden') : __('Disabled') }}</span>
    @endif
    <span @class(['badge badge-sm', 'badge-success' => $wishProduct->isOrderable(), 'badge-warning' => ! $wishProduct->isOrderable()])>{{ $wishProduct->stockLabel() }}</span>
</td>
