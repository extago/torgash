{{-- The rows of a rules screen in the admin's common shape: what this kind of
     rule is for and "+ New", search and tabs, the bulk bar, then the caller's
     columns (rulesCells returns already-escaped HTML per row), the status in
     place, edit | delete. Delete is a form of its own, outside the edit form
     above — never nested. Closes the Alpine root frame.blade.php opened. --}}
@php
    $rulesStateTabs = ['' => __('All'), 'on' => __('Rule works'), 'off' => __('Rule is off')];
    $rulesFiltered = $filters['q'] !== '' || $filters['state'] !== '';
    $rulesAddLabel = ['synonyms' => __('New synonym'), 'exclusions' => __('New exclusion'), 'phrases' => __('New phrase')][$kind];
@endphp
{{-- What this kind of rule is for goes to the heading's hint. --}}
@section('title_hint', $rulesIntro)

{{-- The states as one row of tabs over the card, "+ New" at its end
     (app.css, `admin-tabs`); LINKS carrying the search. Inside the Alpine
     root frame.blade.php opened, so the button reaches `adding`. --}}
<div class="admin-tabs-row">
    <nav class="admin-tabs" aria-label="{{ $rulesAddLabel }}">
        @foreach ($rulesStateTabs as $rulesStateKey => $rulesStateLabel)
            <a href="{{ route('admin.search-engine.rules.index', array_filter(['kind' => $kind, 'q' => $filters['q'] ?: null, 'state' => $rulesStateKey ?: null])) }}"
               @class(['admin-tab', 'is-active' => $filters['state'] === $rulesStateKey])>
                {{ $rulesStateLabel }} <span class="admin-tab-count">{{ $stateCounts[$rulesStateKey] }}</span>
            </a>
        @endforeach
    </nav>
    <div class="admin-tabs-actions">
        <button type="button" class="btn btn-primary" x-show="!adding"
                @click="adding = true; $nextTick(() => document.getElementById('rule-form')?.querySelector('textarea, input:not([type=hidden]):not([type=checkbox])')?.focus())">
            + {{ $rulesAddLabel }}
        </button>
    </div>
</div>

<section class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
    <div class="card-body gap-4">
        <form method="GET" action="{{ route('admin.search-engine.rules.index', $kind) }}" class="flex flex-wrap items-center gap-2">
            <label class="input input-sm grow min-w-48 gap-2">
                <x-icon name="search" size="sm" class="opacity-50" />
                <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Find a word…') }}">
            </label>
            <input type="hidden" name="state" value="{{ $filters['state'] }}">
            @if ($rulesFiltered)
                <a href="{{ route('admin.search-engine.rules.index', $kind) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
            @endif
        </form>

        <div class="min-h-12 flex items-center">
            <p x-show="!selected.length" class="text-xs opacity-50">{{ __('Tick rules to switch several on or off at once, or delete them.') }}</p>
            <form method="POST" action="{{ route('admin.search-engine.rules.bulk', $kind) }}"
                  x-show="selected.length" x-cloak
                  class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                <div class="join">
                    <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Enable') }}</button>
                    <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Disable') }}</button>
                </div>
                <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                        data-confirm="{{ __('Delete the selected rules?') }}">
                    <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                </button>
                <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
            </form>
        </div>

        @if ($rows->isEmpty())
            <div class="text-center py-12 opacity-60">
                <x-icon name="wand-sparkles" size="xl" class="opacity-40" />
                <p class="mt-2">{{ $rulesFiltered ? __('No rules match this search.') : __('Nothing here yet.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-10">
                                <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                       x-on:change="selected = $event.target.checked ? @js($rows->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                       :checked="selected.length === {{ $rows->count() }}">
                            </th>
                            @foreach ($rulesColumns as $rulesColumn)
                                <th>{{ $rulesColumn }}</th>
                            @endforeach
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $rulesRow)
                            <tr @class(['hover:bg-base-200/50', 'bg-primary/5' => $editing && $editing->id === $rulesRow->id])
                                :class="selected.includes('{{ $rulesRow->id }}') && 'bg-primary/5'">
                                <td>
                                    <input type="checkbox" class="checkbox checkbox-sm" value="{{ $rulesRow->id }}" x-model="selected" aria-label="#{{ $rulesRow->id }}">
                                </td>
                                @foreach ($rulesCells($rulesRow) as $rulesCell)
                                    <td @class(['opacity-60' => ! $rulesRow->enabled])>{!! $rulesCell !!}</td>
                                @endforeach
                                @include('torgash.search::admin.rules._status', ['kind' => $kind, 'rulesRow' => $rulesRow])
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('admin.search-engine.rules.edit', [$kind, $rulesRow->id]) }}#rule-form" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        <form method="POST" action="{{ route('admin.search-engine.rules.destroy', [$kind, $rulesRow->id]) }}" class="inline"
                                              data-confirm="{{ __('Delete this rule?') }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
</div>
