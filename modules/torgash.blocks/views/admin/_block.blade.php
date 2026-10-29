{{-- One block on the zone canvas. $place is where it stands: 'single' (a row
     of its own), 'cell' (a column of a row) or 'tab'. $first / $last say
     whether it can move left / right inside its group. Every button names a
     form printed OUTSIDE the canvas (form="…"): a form nested in the layout
     would lose its tag and leak its _method. --}}
<div @class([
        'rounded-box border bg-base-100 p-3 flex flex-col gap-2 min-w-0 h-full',
        'border-base-300' => $block->enabled,
        'border-dashed border-base-300 opacity-60' => ! $block->enabled,
    ])>
    <div class="flex items-start gap-2 min-w-0">
        @php $blockType = \Torgash\Blocks\BlockTypes::find($block->type); @endphp
        <span class="w-8 h-8 rounded-md bg-base-200 flex items-center justify-center shrink-0">
            <x-icon :name="$blockType['icon'] ?? 'square'" size="sm" class="opacity-70" />
        </span>
        <div class="min-w-0 flex-1">
            <a href="{{ route('admin.blocks.edit', $block) }}" class="font-medium link link-hover block truncate">{{ $block->displayTitle() }}</a>
            <div class="text-xs opacity-60 flex items-center gap-1.5 flex-wrap">
                @if ($block->typeIsMissing())
                    <span class="badge badge-warning badge-sm">{{ __('Type unavailable') }}</span>
                @else
                    {{ $block->typeLabel() }}
                @endif
                @if (! empty($block->rules))
                    <span title="{{ __('Shown only under conditions') }}"><x-icon name="filter" size="sm" /></span>
                @endif
                @unless ($block->enabled)
                    <span>· {{ __('off') }}</span>
                @endunless
            </div>
        </div>
        {{-- Up beside the name, where the eye looks first — show, edit,
             and set apart, delete. --}}
        <div class="flex items-center gap-0.5 shrink-0 -mt-1 -me-1">
            <button form="toggle-{{ $block->id }}" class="btn btn-ghost btn-sm btn-square"
                    title="{{ $block->enabled ? __('Switch off') : __('Switch on') }}" aria-label="{{ $block->enabled ? __('Switch off') : __('Switch on') }}">
                <x-icon :name="$block->enabled ? 'eye' : 'eye-off'" size="sm" />
            </button>
            <a href="{{ route('admin.blocks.edit', $block) }}" class="btn btn-ghost btn-sm btn-square"
               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
            <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
            <button form="delete-{{ $block->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                    title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
        </div>
    </div>

    @if ($place === 'cell')
        <select name="width" form="width-{{ $block->id }}" data-submit-on-change
                class="select select-bordered select-sm w-full" aria-label="{{ __('Column width') }}">
            <option value="">{{ __('An equal share') }}</option>
            @foreach (array_keys(\Torgash\Blocks\ZoneRenderer::WIDTHS) as $widthOption)
                <option value="{{ $widthOption }}" @selected($block->width === $widthOption)>{{ $widthOption }}</option>
            @endforeach
        </select>
    @endif

    @if ($place !== 'single')
    <div class="flex items-center gap-1 flex-wrap mt-auto">
            <button form="move-left-{{ $block->id }}" class="btn btn-ghost btn-sm btn-square" @disabled($first)
                    title="{{ __('Move left') }}" aria-label="{{ __('Move left') }}"><x-icon name="arrow-left" size="sm" /></button>
            <button form="move-right-{{ $block->id }}" class="btn btn-ghost btn-sm btn-square" @disabled($last)
                    title="{{ __('Move right') }}" aria-label="{{ __('Move right') }}"><x-icon name="arrow-right" size="sm" /></button>
            <button form="detach-{{ $block->id }}" class="btn btn-ghost btn-sm btn-square"
                    title="{{ __('Take out on its own') }}" aria-label="{{ __('Take out on its own') }}"><x-icon name="square-arrow-out-down-right" size="sm" /></button>
    </div>
    @endif
</div>
