{{-- What a category item opens into NOW (PLAN §39.7), from the form as it
     stands: every subcategory the item reaches, the ones it leaves out greyed
     with the reason. Decided by MenuBuilder::sift(), the storefront's own. --}}
@php
    $previewWhy = [
        'excluded' => __('excluded on this item'),
        'empty' => __('empty by the rule'),
        'limit' => __('past the limit — behind "N more"'),
    ];
    $previewRule = $preview['rule'];
@endphp
<div class="flex flex-col gap-2 text-sm">
    @if ($preview['self_hidden'])
        <div class="alert alert-warning text-sm py-2">
            <x-icon name="eye-off" size="sm" />
            <span>{{ __('The item itself is not drawn now: its category is empty by this rule, and "hide the item itself" is ticked.') }}</span>
        </div>
    @elseif ($preview['self_count'] !== null)
        <div class="opacity-70">
            {{ $item->sourceCategory?->t('name') }} —
            {{ trans_choice(':count product|:count products', $preview['self_count'], ['count' => $preview['self_count']]) }}
        </div>
    @endif

    @if ($preview['rows'] === [])
        <div class="opacity-60">{{ (int) $item->option('depth', 1) === 0 ? __('No levels down: only the category itself.') : __('This category has no active subcategories.') }}</div>
    @else
        @php
            $previewTree = function (array $rows) use (&$previewTree, $previewWhy): string {
                $html = '<ul class="flex flex-col gap-0.5">';

                foreach ($rows as $row) {
                    $html .= '<li><div class="flex items-center gap-2'.($row['why'] ? ' opacity-50' : '').'">'
                        .'<span class="'.($row['why'] ? 'line-through' : 'font-medium').'">'.e($row['category']->t('name')).'</span>'
                        .($row['count'] !== null ? '<span class="badge badge-ghost badge-sm tabular-nums">'.e($row['count']).'</span>' : '')
                        .($row['why'] ? '<span class="text-xs italic">'.e($previewWhy[$row['why']] ?? $row['why']).'</span>' : '')
                        .'</div>';

                    if ($row['children'] !== []) {
                        $html .= '<div class="ml-5 border-l border-base-300 pl-3">'.$previewTree($row['children']).'</div>';
                    }

                    $html .= '</li>';
                }

                return $html.'</ul>';
            };
        @endphp
        {!! $previewTree($preview['rows']) !!}
    @endif

    <div class="text-xs opacity-60">
        {{ __('Counted just now, for the products the storefront shows. Numbers are:') }}
        {{ match ($previewRule) {
            'orderable' => __('what can be bought'),
            'in_stock' => __('what is on the shelf'),
            default => __('all products shown'),
        } }}.
        @if ($item->option('replace', false))
            {{ __('These stand in place of the item itself.') }}
        @endif
    </div>
</div>
