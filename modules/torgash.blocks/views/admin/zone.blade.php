@extends('admin.layout')

@section('title', label_text($zone['label'] ?? null, null, $zone['name']))
@section('own_heading', '1')

@php
    use App\Core\Zones\ZoneRegistry;
    use Torgash\Blocks\BlockTypes;
    use Torgash\Blocks\ZoneRenderer;

    $shapeWords = [
        'wide' => __('Blocks here get the width of the page\'s content. Several can stand side by side as columns.'),
        'narrow' => __('A narrow column: blocks stand one under another, and columns rarely make sense here.'),
        'inline' => __('A line in the header: short controls standing one after another.'),
        'drawer' => __('The phone\'s menu: rows, one under another.'),
    ];
    $spanClass = [
        1 => 'md:col-span-1', 2 => 'md:col-span-2', 3 => 'md:col-span-3', 4 => 'md:col-span-4',
        5 => 'md:col-span-5', 6 => 'md:col-span-6', 7 => 'md:col-span-7', 8 => 'md:col-span-8',
        9 => 'md:col-span-9', 10 => 'md:col-span-10', 11 => 'md:col-span-11', 12 => 'md:col-span-12',
    ];
    $blocks = collect($groups)->flatMap(fn ($group) => $group['blocks']);
@endphp

@section('content')
    <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3 flex-wrap">
        <a href="{{ route('admin.blocks.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('All zones') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none flex items-center gap-2">
                <span class="font-mono">{{ $zone['name'] }}</span>
                <span class="badge badge-ghost badge-xs">{{ __(ZoneRegistry::SHAPES[$shape] ?? $shape) }}</span>
            </div>
            <div class="font-semibold truncate leading-tight">{{ label_text($zone['label'] ?? null, null, $zone['name']) }}</div>
        </div>
        <form method="POST" action="{{ route('admin.blocks.zone.toggle', $zone['name']) }}" class="flex items-center gap-2">
            @csrf @method('PATCH')
            <span class="text-sm opacity-70">{{ $zoneEnabled ? __('Zone is on') : __('Zone is off') }}</span>
            <input type="checkbox" class="toggle toggle-sm toggle-success" @checked($zoneEnabled) data-submit-on-change
                   aria-label="{{ $zoneEnabled ? __('Disable zone') : __('Enable zone') }}">
        </form>
        <button type="button" class="btn btn-primary btn-sm" data-open-dialog="modal-block-picker">
            + {{ __('Add block') }}
        </button>
    </div>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    @unless ($zoneEnabled)
        <div class="alert alert-warning text-sm">
            <x-icon name="eye-off" size="sm" />
            <span>{{ __('This zone is switched off — its blocks are not rendered on the storefront. They are kept as they are and come back when you switch it on.') }}</span>
        </div>
    @endunless

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ $shapeWords[$shape] ?? '' }}
                {{ __('Blocks are drawn in this order. Join a block with the one below it to put them side by side as columns or as tabs; arrows move a whole row at once.') }}
            </p>

            @if ($groups === [])
                <div class="text-center py-12 opacity-60">
                    <x-icon name="layout-template" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('Nothing in this zone yet.') }}</p>
                    <button type="button" class="btn btn-sm mt-3" data-open-dialog="modal-block-picker">+ {{ __('Add block') }}</button>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($groups as $groupIndex => $group)
                        @php
                            $lead = $group['blocks'][0];
                            $isFirst = $groupIndex === 0;
                            $isLast = $groupIndex === count($groups) - 1;
                        @endphp
                        {{-- One group: its own up/down, what it is, and the way to
                             join it with the group below. --}}
                        <div @class([
                                'flex gap-2 items-stretch',
                            ])>
                            <div class="flex flex-col gap-1 shrink-0 pt-1">
                                <button form="move-up-{{ $lead->id }}" class="btn btn-ghost btn-sm btn-square" @disabled($isFirst)
                                        title="{{ __('Move up') }}" aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="sm" /></button>
                                <button form="move-down-{{ $lead->id }}" class="btn btn-ghost btn-sm btn-square" @disabled($isLast)
                                        title="{{ __('Move down') }}" aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="sm" /></button>
                            </div>

                            <div class="flex-1 min-w-0 flex flex-col gap-2">
                                {{-- The band this row or block stands in (PLAN §51) —
                                     the group's, kept on its first block. Only where
                                     blocks have the page's width to step out of. --}}
                                @if ($shape === 'wide')
                                    <button type="button" class="self-start flex items-center gap-1.5 text-xs opacity-60 hover:opacity-100 px-1"
                                            data-open-dialog="modal-section-{{ $lead->id }}">
                                        <x-icon name="rectangle-horizontal" size="sm" />
                                        {{ __('Section on the page') }}: <span @class(['font-medium' => $lead->section])>{{ \Torgash\Blocks\Section::summary($lead->section) }}</span>
                                        <x-icon name="pencil" size="sm" class="opacity-60" />
                                    </button>
                                @endif
                                @if ($group['kind'] === 'single')
                                    @include('torgash.blocks::admin._block', ['block' => $lead, 'place' => 'single', 'first' => true, 'last' => true])
                                @elseif ($group['kind'] === 'row')
                                    @php $spans = ZoneRenderer::spans(array_map(fn ($member) => $member->width, $group['blocks'])); @endphp
                                    <div class="rounded-box bg-base-200/60 p-2 flex flex-col gap-2">
                                        <div class="text-xs opacity-60 flex items-center gap-1.5 px-1">
                                            <x-icon name="columns-3" size="sm" /> {{ trans_choice(':count column side by side|:count columns side by side', count($group['blocks']), ['count' => count($group['blocks'])]) }}
                                        </div>
                                        {{-- The row at its real proportions, as the storefront
                                             draws it from a tablet up. --}}
                                        <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
                                            @foreach ($group['blocks'] as $memberIndex => $member)
                                                <div class="{{ $spanClass[$spans[$memberIndex]] ?? 'md:col-span-12' }} min-w-0">
                                                    @include('torgash.blocks::admin._block', ['block' => $member, 'place' => 'cell',
                                                        'first' => $memberIndex === 0, 'last' => $memberIndex === count($group['blocks']) - 1])
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded-box bg-base-200/60 p-2 flex flex-col gap-2">
                                        <div class="text-xs opacity-60 flex items-center gap-1.5 px-1">
                                            <x-icon name="panels-top-left" size="sm" /> {{ trans_choice(':count tab|:count tabs', count($group['blocks']), ['count' => count($group['blocks'])]) }}
                                            <span class="opacity-70">— {{ __('each named by its block\'s heading') }}</span>
                                        </div>
                                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            @foreach ($group['blocks'] as $memberIndex => $member)
                                                @include('torgash.blocks::admin._block', ['block' => $member, 'place' => 'tab',
                                                    'first' => $memberIndex === 0, 'last' => $memberIndex === count($group['blocks']) - 1])
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @unless ($isLast)
                                    {{-- Between this group and the next: the one place a
                                         row or a set of tabs is made. --}}
                                    <div class="flex items-center gap-2 text-xs opacity-60 hover:opacity-100 pl-1">
                                        <span>{{ __('Join with the block below:') }}</span>
                                        @if ($group['kind'] !== 'tabs')
                                            <button form="join-row-{{ $lead->id }}" class="btn btn-ghost btn-sm gap-1"><x-icon name="columns-2" size="sm" /> {{ __('as columns') }}</button>
                                        @endif
                                        @if ($group['kind'] !== 'row')
                                            <button form="join-tabs-{{ $lead->id }}" class="btn btn-ghost btn-sm gap-1"><x-icon name="panels-top-left" size="sm" /> {{ __('as tabs') }}</button>
                                        @endif
                                    </div>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- The picker: what makes sense in a zone of this shape first, each with
         what it does; the rest one click away rather than forbidden. --}}
    <x-modal name="block-picker" :title="__('Add a block')" icon="layout-template" size="lg">
        <div class="flex flex-col gap-4" x-data="{ all: false }">
            <p class="text-sm opacity-70">{{ __('Blocks that suit this zone. Choose one to set it up; it is added at the end of the zone.') }}</p>

            @php
                $pickerCard = function (string $name, array $blockType, bool $fits) use ($zone) {
                    return [
                        'url' => route('admin.blocks.create', [$zone['name'], $name]),
                        'icon' => $blockType['icon'] ?? 'square',
                        'label' => label_text($blockType['label'] ?? null, null, $name),
                        'description' => ! empty($blockType['description']) ? __($blockType['description']) : '',
                        'fits' => collect((array) ($blockType['fits'] ?? ['wide', 'narrow']))
                            ->map(fn ($s) => __(ZoneRegistry::SHAPES[$s] ?? $s))->implode(' · '),
                        'muted' => ! $fits,
                    ];
                };
            @endphp

            <div class="grid gap-2 sm:grid-cols-2">
                @forelse ($fitting as $typeName => $blockType)
                    @php $card = $pickerCard($typeName, $blockType, true); @endphp
                    <a href="{{ $card['url'] }}" class="flex gap-3 p-3 rounded-box border border-base-300 hover:border-primary hover:bg-primary/5">
                        <span class="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0"><x-icon :name="$card['icon']" /></span>
                        <span class="min-w-0">
                            <span class="font-medium block">{{ $card['label'] }}</span>
                            @if ($card['description'])<span class="text-xs opacity-70 block">{{ $card['description'] }}</span>@endif
                        </span>
                    </a>
                @empty
                    <p class="text-sm opacity-60">{{ __('No block type is made for this kind of zone.') }}</p>
                @endforelse
            </div>

            @if ($others)
                <button type="button" class="btn btn-ghost btn-sm self-start gap-1.5" x-on:click="all = ! all">
                    <x-icon name="chevron-down" size="sm" /> <span x-text="all ? @js(__('Hide the rest')) : @js(__('Show all block types'))"></span>
                </button>
                <div x-show="all" x-cloak class="flex flex-col gap-2">
                    <p class="text-xs opacity-60">{{ __('These are made for other places. They can still be put here — it may just look out of place.') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($others as $typeName => $blockType)
                            @php $card = $pickerCard($typeName, $blockType, false); @endphp
                            <a href="{{ $card['url'] }}" class="flex gap-3 p-3 rounded-box border border-dashed border-base-300 hover:border-base-content/40 opacity-80">
                                <span class="w-10 h-10 rounded-lg bg-base-200 flex items-center justify-center shrink-0"><x-icon :name="$card['icon']" class="opacity-60" /></span>
                                <span class="min-w-0">
                                    <span class="font-medium block">{{ $card['label'] }}</span>
                                    @if ($card['description'])<span class="text-xs opacity-70 block">{{ $card['description'] }}</span>@endif
                                    <span class="text-xs opacity-50 block mt-0.5">{{ __('Made for:') }} {{ $card['fits'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </x-modal>

    {{-- Forms live outside the canvas: a <form> nested in the layout would
         have its tag dropped by the parser while its inputs stayed behind. --}}
    @foreach ($blocks as $block)
        <form id="toggle-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.toggle', $block) }}" class="hidden">@csrf @method('PATCH')</form>
        @foreach (['up', 'down', 'left', 'right'] as $direction)
            <form id="move-{{ $direction }}-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.move', $block) }}" class="hidden">
                @csrf @method('PATCH')<input type="hidden" name="direction" value="{{ $direction }}">
            </form>
        @endforeach
        <form id="join-row-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.join', $block) }}" class="hidden">@csrf @method('PATCH')<input type="hidden" name="as" value="row"></form>
        <form id="join-tabs-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.join', $block) }}" class="hidden">@csrf @method('PATCH')<input type="hidden" name="as" value="tabs"></form>
        <form id="detach-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.detach', $block) }}" class="hidden">@csrf @method('PATCH')</form>
        <form id="width-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.width', $block) }}" class="hidden">@csrf @method('PATCH')</form>
        <form id="delete-{{ $block->id }}" method="POST" action="{{ route('admin.blocks.destroy', $block) }}" class="hidden"
              data-confirm="{{ __('Delete this block?') }}">@csrf @method('DELETE')</form>
    @endforeach

    @if ($shape === 'wide')
        @foreach ($groups as $group)
            <x-modal name="section-{{ $group['blocks'][0]->id }}" :title="__('Section on the page')" icon="rectangle-horizontal">
                @include('torgash.blocks::admin._section', ['sectionLead' => $group['blocks'][0]])
            </x-modal>
        @endforeach
    @endif
@endsection
