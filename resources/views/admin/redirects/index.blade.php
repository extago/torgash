@extends('admin.layout')

@section('title', __('Redirects'))

@section('content')
    @php
        $isFiltered = $filters['q'] !== '' || $filters['source'] !== '' || $filters['sort'] !== 'recent';
        $sourceLabels = ['manual' => __('Added by hand'), 'auto' => __('Address changed'), 'import' => __('Imported')];
        $sourceTabs = ['' => __('All')] + $sourceLabels;
        // Open the "new" row again after a refusal, with what was typed in it.
        $addingAgain = old('from_path') !== null && ! old('_redirect_id');
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [], editing: null, adding: @js($addingAgain) }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Old addresses and where they lead now. The shop adds a row by itself whenever a slug changes; these are the ones you add — above all the list of addresses from whatever the shop ran on before.') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.redirects.export') }}" class="btn btn-ghost btn-sm gap-1.5" title="{{ __('Download every redirect as CSV, in the shape the import reads') }}">
                        <x-icon name="download" size="sm" /> {{ __('Export') }}
                    </a>
                    <button type="button" class="btn btn-outline btn-sm gap-1.5" data-open-dialog="modal-redirects-import">
                        <x-icon name="upload" size="sm" /> {{ __('Import a list') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" x-on:click="adding = true; $nextTick(() => $refs.newFrom.focus())">+ {{ __('New redirect') }}</button>
                </div>
            </div>

            {{-- The new row: two full-size fields, an arrow between them. --}}
            <form method="POST" action="{{ route('admin.redirects.store') }}" x-show="adding" x-cloak
                  class="rounded-box border border-primary/40 bg-primary/5 p-3 flex flex-wrap items-end gap-3">
                @csrf
                <fieldset class="fieldset p-0 grow basis-72">
                    <legend class="fieldset-legend">{{ __('Old address') }}</legend>
                    <input name="from_path" x-ref="newFrom" value="{{ $addingAgain ? old('from_path') : '' }}" required autocomplete="off"
                           class="input input-bordered w-full font-mono text-sm" placeholder="/old-page">
                </fieldset>
                <x-icon name="arrow-right" class="opacity-40 mb-3 hidden sm:block" />
                <fieldset class="fieldset p-0 grow basis-72">
                    <legend class="fieldset-legend">{{ __('Goes to') }}</legend>
                    <input name="to_path" value="{{ $addingAgain ? old('to_path') : '' }}" required autocomplete="off"
                           class="input input-bordered w-full font-mono text-sm" placeholder="/catalog/laptops">
                </fieldset>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-ghost" x-on:click="adding = false">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary">{{ __('Add') }}</button>
                </div>
                <p class="basis-full text-xs opacity-60">{{ __('Paste whole addresses from the browser if that is easier — this shop\'s own host is cut off. The destination may be another site.') }}</p>
            </form>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-80 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search in either address…') }}">
                </label>
                <select name="sort" class="select select-bordered select-sm w-full sm:w-64" data-submit-on-change>
                    <option value="recent" @selected($filters['sort'] === 'recent')>{{ __('Recently changed first') }}</option>
                    <option value="used" @selected($filters['sort'] === 'used')>{{ __('Most used first') }}</option>
                    <option value="unused" @selected($filters['sort'] === 'unused')>{{ __('Never used') }}</option>
                </select>
                <input type="hidden" name="source" value="{{ $filters['source'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.redirects.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($sourceTabs as $sourceKey => $sourceLabel)
                    <a href="{{ request()->fullUrlWithQuery(['source' => $sourceKey ?: null, 'page' => null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['source'] === $sourceKey])>
                        {{ $sourceLabel }} <span class="opacity-50">{{ $counts[$sourceKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Every redirect is permanent (301). "Used" counts the visits it has sent on — an old address nobody arrives through any more can go.') }}
                </p>
                <form method="POST" action="{{ route('admin.redirects.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Remove the selected redirects? Their old addresses will answer 404.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Remove') }}
                    </button>
                    <button name="action" value="keep" class="btn btn-sm btn-ghost gap-1.5"
                            title="{{ __('Automatic redirects nobody uses are forgotten after a while; kept ones never are.') }}">
                        <x-icon name="pin" size="sm" /> {{ __('Keep for ever') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($rows->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="signpost" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $isFiltered ? __('No redirects match this search.') : __('No redirects yet. The shop adds one by itself when an address changes; a list from the old shop goes in through "Import a list".') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($rows->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values()) : []"
                                           :checked="selected.length === {{ $rows->count() }}">
                                </th>
                                <th>{{ __('Old address') }}</th>
                                <th class="w-6"></th>
                                <th>{{ __('Goes to') }}</th>
                                <th>{{ __('Source') }}</th>
                                <th class="text-end">{{ __('Times used') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $editingThis = (int) old('_redirect_id') === (int) $row->id;
                                @endphp
                                <tr class="hover:bg-base-200/50" x-show="editing !== {{ $row->id }}"
                                    :class="selected.includes('{{ $row->id }}') && 'bg-primary/5'"
                                    @if ($editingThis) x-init="editing = {{ $row->id }}" @endif>
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $row->id }}" x-model="selected" aria-label="{{ $row->from_path }}">
                                    </td>
                                    <td class="font-mono text-xs break-all max-w-md">{{ $row->from_path }}</td>
                                    <td class="px-0"><x-icon name="arrow-right" size="sm" class="opacity-40" /></td>
                                    <td class="font-mono text-xs break-all max-w-md">
                                        @if (str_starts_with($row->to_path, '/'))
                                            {{ $row->to_path }}
                                        @else
                                            <span class="inline-flex items-center gap-1"><x-icon name="globe" size="sm" class="opacity-50 shrink-0" />{{ $row->to_path }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap opacity-70">{{ $sourceLabels[$row->source] ?? '—' }}</td>
                                    <td class="text-end whitespace-nowrap">
                                        @if ($row->hits > 0)
                                            <div class="tabular-nums font-semibold">{{ number_format($row->hits) }}</div>
                                            @if ($row->last_hit_at)
                                                <div class="text-xs opacity-50" title="{{ $row->last_hit_at }}">{{ \Illuminate\Support\Carbon::parse($row->last_hit_at)->diffForHumans() }}</div>
                                            @endif
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            @if (str_starts_with($row->from_path, '/'))
                                                {{-- Opens the OLD address: the way to see the redirect work. --}}
                                                <a href="{{ url($row->from_path) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square"
                                                   title="{{ __('Try the old address') }}" aria-label="{{ __('Try the old address') }}"><x-icon name="external-link" /></a>
                                            @endif
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" x-on:click="editing = {{ $row->id }}"
                                                    title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></button>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.redirects.destroy', $row->id) }}" class="inline"
                                                  data-confirm="{{ __('Remove this redirect? The old address will answer 404.') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                {{-- The same row, corrected in place. --}}
                                <tr x-show="editing === {{ $row->id }}" x-cloak class="bg-primary/5">
                                    <td colspan="7">
                                        <form method="POST" action="{{ route('admin.redirects.update', $row->id) }}" class="flex flex-wrap items-end gap-3">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="_redirect_id" value="{{ $row->id }}">
                                            <fieldset class="fieldset p-0 grow basis-72">
                                                <legend class="fieldset-legend">{{ __('Old address') }}</legend>
                                                <input name="from_path" value="{{ $editingThis ? old('from_path') : $row->from_path }}" required autocomplete="off"
                                                       class="input input-bordered w-full font-mono text-sm">
                                            </fieldset>
                                            <x-icon name="arrow-right" class="opacity-40 mb-3 hidden sm:block" />
                                            <fieldset class="fieldset p-0 grow basis-72">
                                                <legend class="fieldset-legend">{{ __('Goes to') }}</legend>
                                                <input name="to_path" value="{{ $editingThis ? old('to_path') : $row->to_path }}" required autocomplete="off"
                                                       class="input input-bordered w-full font-mono text-sm">
                                            </fieldset>
                                            <div class="flex gap-2">
                                                <button type="button" class="btn btn-ghost" x-on:click="editing = null">{{ __('Cancel') }}</button>
                                                <button class="btn btn-primary">{{ __('Save') }}</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $rows->links() }}
            @endif
        </div>
    </div>

    <x-modal name="redirects-import" :title="__('Import a list')" icon="upload" size="lg">
        <form method="POST" action="{{ route('admin.redirects.import') }}" enctype="multipart/form-data" class="flex flex-col gap-3">
            @csrf
            <p class="text-sm opacity-70">
                {{ __('Two columns per line, comma or semicolon separated: the old address, then the new one. A header row is ignored. An address that already has a redirect is updated.') }}
            </p>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Paste the list') }}</legend>
                <textarea name="pasted" rows="8" spellcheck="false"
                          class="textarea textarea-bordered w-full font-mono text-xs"
                          placeholder="/index.php?dispatch=products.view&amp;product_id=12,/product/laptop-pro-14"></textarea>
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('…or choose a file') }}</legend>
                <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" class="file-input file-input-bordered w-full">
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('A file wins over pasted text. The export of this screen imports back unchanged.') }}</p>
            </fieldset>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('Cancel') }}</button>
                <button class="btn btn-primary gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Import') }}</button>
            </div>
        </form>
    </x-modal>
@endsection
