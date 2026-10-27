@php
    $rowKinds = app(\Torgash\Menus\ItemKinds::class);
    $rowKind = $rowKinds->find($item->kind);
    $rowIcon = $item->icon && is_file(resource_path('icons/lucide/'.$item->icon.'.svg')) ? $item->icon : null;
    $rowOptions = (array) ($item->options ?? []);
    $rowRuleWords = [
        'listed' => __('only with products'),
        'orderable' => __('only what can be bought'),
        'in_stock' => __('only what is on the shelf'),
    ];
    $rowChildren = $byParent->get($item->id, collect());
@endphp
<li data-menu-item="{{ $item->id }}" id="item-{{ $item->id }}" class="scroll-mt-24 [&:target>div]:bg-primary/10">
    {{-- The row is what is dragged and what is dropped ON; the drop's place
         (before / inside / after) is where the pointer is, marked by
         data-drop, which the script sets and these classes draw. --}}
    <div data-menu-row draggable="false"
         @class([
             'flex items-center gap-2 px-2 py-1.5 rounded-lg border border-transparent hover:bg-base-200/60',
             'data-[drop=before]:shadow-[inset_0_2px_0_var(--color-primary)] data-[drop=after]:shadow-[inset_0_-2px_0_var(--color-primary)] data-[drop=inside]:border-primary data-[drop=inside]:bg-primary/5',
             'data-[dragging]:opacity-40',
             'opacity-50' => ! $item->enabled,
         ])>
        <span data-menu-handle class="hidden cursor-grab active:cursor-grabbing opacity-40 hover:opacity-100 shrink-0" title="{{ __('Drag to move; drop onto the middle of a row to put it inside') }}">
            <x-icon name="grip-vertical" size="sm" />
        </span>
        <span class="w-7 h-7 rounded-md bg-base-200 flex items-center justify-center shrink-0 opacity-80">
            <x-icon :name="$rowIcon ?? ($rowKind['icon'] ?? 'circle-help')" size="sm" />
        </span>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.menus.items.edit', [$menu, $item]) }}" @class(['link link-hover', 'text-primary font-semibold' => $item->highlight])>{{ $item->adminLabel() ?: '—' }}</a>
                @if ($item->t('badge'))
                    <span class="badge badge-{{ $item->badge_style ?: 'primary' }} badge-xs">{{ $item->t('badge') }}</span>
                @endif
                @if ($item->show_on !== 'all')
                    <span class="badge badge-ghost badge-sm gap-1"><x-icon :name="$item->show_on === 'mobile' ? 'smartphone' : 'monitor'" size="sm" /></span>
                @endif
                @if (! empty($item->rules))
                    <span class="badge badge-ghost badge-sm gap-1" title="{{ __('Shown only under conditions') }}"><x-icon name="filter" size="sm" /></span>
                @endif
            </div>
            <div class="text-xs opacity-60 truncate">
                {{ $rowKinds->options()[$item->kind] ?? $item->kind }}
                @if ($item->kind === 'category')
                    @php
                        $rowDepth = (int) ($rowOptions['depth'] ?? 1);
                        $rowSummary = array_filter([
                            trans_choice(':count level down|:count levels down', $rowDepth, ['count' => $rowDepth]),
                            $rowRuleWords[$rowOptions['show_when'] ?? ''] ?? null,
                            ! empty($rowOptions['replace']) ? __('in place of the item') : null,
                        ]);
                    @endphp
                    · {{ implode(' · ', $rowSummary) }}
                @endif
            </div>
        </div>

        {{-- The arrows: what moves an item with no script and from the keyboard. --}}
        <form method="POST" action="{{ route('admin.menus.items.move', [$menu, $item]) }}" class="flex items-center shrink-0">
            @csrf
            <button name="direction" value="up" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move up') }}" aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="sm" /></button>
            <button name="direction" value="down" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move down') }}" aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="sm" /></button>
            <button name="direction" value="in" class="btn btn-ghost btn-sm btn-square" title="{{ __('Put inside the item above') }}" aria-label="{{ __('Put inside the item above') }}"><x-icon name="indent-increase" size="sm" /></button>
            <button name="direction" value="out" class="btn btn-ghost btn-sm btn-square" title="{{ __('Take out of its parent') }}" aria-label="{{ __('Take out of its parent') }}"><x-icon name="indent-decrease" size="sm" /></button>
        </form>
        {{-- Edit, and — set apart — delete: the lists' own row actions. --}}
        <div class="inline-flex items-center gap-1 shrink-0">
            <a href="{{ route('admin.menus.items.edit', [$menu, $item]) }}" class="btn btn-ghost btn-sm btn-square"
               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
            <form method="POST" action="{{ route('admin.menus.items.destroy', [$menu, $item]) }}" class="inline"
                  data-confirm="{{ $rowChildren->isNotEmpty() ? __('Delete this item and everything inside it?') : __('Delete this item?') }}">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
            </form>
        </div>
    </div>
    {{-- Always drawn, empty or not: it is where a row dropped "inside" goes. --}}
    <ol data-menu-children class="ml-6 border-l border-base-300 pl-2 empty:hidden">@foreach ($rowChildren as $childItem)@include('torgash.menus::admin.partials.item-row', ['item' => $childItem])@endforeach</ol>
</li>
