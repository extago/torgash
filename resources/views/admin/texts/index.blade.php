@extends('admin.layout')

@section('title', __('Texts'))
@section('title_hint', __('Every word the shop prints, in each of its languages. Call a thing what you like — "Cashback" for "Bonus points" — and your wording outlives an update. Plain words only; a :placeholder in a text stays, the shop fills it in.'))

@section('content')
    @php
        $textsTabs = ['' => __('All'), '1' => __('Changed')];
        $textsRetry = old('key');
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- All / Changed as one row of tabs over the card (app.css, `admin-tabs`):
         LINKS, carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Texts') }}">
            @foreach ($textsTabs as $tabKey => $tabLabel)
                <a href="{{ request()->fullUrlWithQuery(['changed' => $tabKey ?: null, 'page' => null]) }}"
                   @class(['admin-tab', 'is-active' => ($filters['changed'] ? '1' : '') === $tabKey])>
                    {{ $tabLabel }} <span class="admin-tab-count">{{ $tabKey === '' ? $counts['all'] : $counts['changed'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search a text in any language…') }}" autofocus>
                </label>
                @if ($filters['changed'])
                    <input type="hidden" name="changed" value="1">
                @endif
                <button class="btn btn-sm">{{ __('Search') }}</button>
                @if ($filters['q'] !== '')
                    <a href="{{ route('admin.texts.index', $filters['changed'] ? ['changed' => 1] : []) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($rows->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="text" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $filters['q'] !== '' ? __('No text matches this search.') : __('Nothing changed yet: every text reads as the engine wrote it.') }}</p>
                </div>
            @else
                {{-- One form a text: the English it is in the code as, who
                     declared it, and a field for each language. Save shows
                     itself once a field is touched; a text with the shop's
                     own wording says so and offers the file's back. --}}
                <div class="flex flex-col gap-2">
                    @foreach ($rows as $row)
                        @php $rowRetry = $textsRetry === $row['key']; @endphp
                        <form method="POST" action="{{ route('admin.texts.save') }}"
                              x-data="{ dirty: @js($rowRetry) }" x-on:input="dirty = true"
                              @class(['rounded-box border p-3 flex flex-col gap-2', 'border-primary/40 bg-primary/5' => $row['changed'], 'border-base-300' => ! $row['changed']])>
                            @csrf
                            <input type="hidden" name="key" value="{{ $row['key'] }}">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="font-medium break-words">{{ $row['key'] }}</span>
                                @if ($row['owner'] !== '')
                                    <span class="badge badge-ghost badge-sm">{{ __($row['owner']) }}</span>
                                @endif
                                @if ($row['changed'])
                                    <span class="badge badge-primary badge-sm">{{ __('Changed') }}</span>
                                @endif
                            </div>
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($languages as $language)
                                    @php
                                        $cell = $row['values'][$language->code];
                                        $cellValue = $rowRetry ? (string) old('values.'.$language->code) : $cell['shown'];
                                        // A sentence or a hint gets a box that shows it whole; a word, a line.
                                        $cellLong = mb_strlen($row['key']) > 80 || mb_strlen($cellValue) > 80;
                                    @endphp
                                    <label class="fieldset p-0">
                                        <span class="fieldset-legend">{{ $language->native_name }}</span>
                                        @if ($cellLong)
                                            <textarea name="values[{{ $language->code }}]" class="textarea textarea-sm w-full leading-snug" rows="3"
                                                      placeholder="{{ $cell['file'] }}">{{ $cellValue }}</textarea>
                                        @else
                                            <input name="values[{{ $language->code }}]" class="input input-sm w-full"
                                                   value="{{ $cellValue }}" placeholder="{{ $cell['file'] }}">
                                        @endif
                                        @if ($cell['own'] !== null && $cell['file'] !== '')
                                            <span class="text-xs opacity-50 break-words">{{ __('Was: :text', ['text' => $cell['file']]) }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            <div class="flex items-center gap-2" x-show="dirty || @js($row['changed'])" @unless ($rowRetry || $row['changed']) x-cloak @endunless>
                                <button name="action" value="save" class="btn btn-sm btn-primary" x-show="dirty">{{ __('Save') }}</button>
                                @if ($row['changed'])
                                    <button name="action" value="reset" class="btn btn-sm btn-ghost">{{ __('Back to the original') }}</button>
                                @endif
                            </div>
                        </form>
                    @endforeach
                </div>

                {{ $rows->links() }}
            @endif
        </div>
    </div>
@endsection
