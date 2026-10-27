{{-- Bulk bar, shared by all three lists, in the slot every list keeps above
     its table. $actions: value => label. $total: how many the current filter
     matched. $hint: what the slot says while nothing is ticked. --}}
<div class="min-h-12 flex items-center">
    <p x-show="!selected.length && !applyToAll" class="text-xs opacity-50">{{ $hint }}</p>
    <div x-show="selected.length > 0 || applyToAll" x-cloak
         class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
        <span class="text-sm px-1">
            <span x-show="! applyToAll"><span x-text="selected.length"></span> {{ __('selected') }}</span>
            <span x-show="applyToAll">{{ __('all :count found', ['count' => number_format($total, 0, '.', ' ')]) }}</span>
        </span>

        @if ($total > 0)
            <label class="label cursor-pointer justify-start gap-2 p-0 text-sm">
                <input type="checkbox" x-model="applyToAll" name="all" value="1" class="checkbox checkbox-sm">
                {{-- The whole point of the feature: 192 000 rows are never getting
                     ticked one by one. --}}
                <span>{{ __('Apply to all :count found', ['count' => number_format($total, 0, '.', ' ')]) }}</span>
            </label>
        @endif

        <div class="join">
            @foreach (array_diff_key($actions, ['delete' => true]) as $value => $label)
                <button name="action" value="{{ $value }}" class="btn btn-sm join-item">{{ $label }}</button>
            @endforeach
        </div>
        @isset($actions['delete'])
            <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                    @click="if (! confirm(applyToAll
                        ? @js(__('Delete all :count found? This cannot be undone.', ['count' => number_format($total, 0, '.', ' ')]))
                        : @js(__('Delete the selected places? This cannot be undone.')))) $event.preventDefault()">
                <x-icon name="trash-2" size="sm" /> {{ $actions['delete'] }}
            </button>
        @endisset
        <button type="button" class="btn btn-ghost btn-sm ml-auto" @click="selected = []; applyToAll = false">{{ __('Clear selection') }}</button>
    </div>
</div>
