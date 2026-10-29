@extends('admin.layout')

@section('title', __('Errors'))
@section('title_hint', __('What broke on the shop\'s pages and in its jobs, grouped by place: when each last happened, how often, and whether the code has changed since.'))

{{--
    What broke, grouped.

    One row per KIND of failure with a counter, not one per occurrence: a
    thousand identical 500s are one line saying "1000 times, last three minutes
    ago", which is the sentence somebody can act on. Where it happened is the
    shop's own file, and a row whose file was saved after it last happened says
    so — that is the list somebody fixing things works down first.
--}}

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $tabs = [
            'open' => [__('Still open'), 'circle-alert'],
            'changed' => [__('Code changed since'), 'file-pen'],
            'resolved' => [__('Dealt with'), 'circle-check'],
        ];
        $tabUrl = fn (string $tab) => route('admin.guard.errors.index', array_filter(['tab' => $tab === 'open' ? null : $tab, 'q' => $filters['q'] ?: null, 'level' => $filters['level'] ?: null]));
    @endphp

    {{-- The states as one row of tabs over the card (app.css, `admin-tabs`);
         "code changed since" worth a look is blue. --}}
    <nav class="admin-tabs" aria-label="{{ __('Errors') }}">
        @foreach ($tabs as $tabKey => [$tabLabel, $tabIcon])
            @php $notable = $tabKey === 'changed' && $tabCounts['changed']; @endphp
            <a href="{{ $tabUrl($tabKey) }}" @class(['admin-tab', 'is-active' => $filters['tab'] === $tabKey])>
                <x-icon :name="$tabIcon" size="sm" /> {{ $tabLabel }}
                <span @class(['admin-tab-count', 'is-info' => $notable])>{{ $tabCounts[$tabKey] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- One Alpine root: the bulk bar and every row's box share `selected`. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['tab'] !== 'open')
                    <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Message, class or file…') }}">
                </label>
                <select name="level" class="select select-sm w-40" data-submit-on-change>
                    <option value="">{{ __('Any level') }}</option>
                    @foreach (\Torgash\Guard\Errors\Recorder::LEVELS as $level)
                        <option value="{{ $level }}" @selected($filters['level'] === $level)>{{ $level }}</option>
                    @endforeach
                </select>
                @if ($filters['q'] !== '' || $filters['level'] !== '')
                    <a href="{{ route('admin.guard.errors.index', array_filter(['tab' => $filters['tab'] === 'open' ? null : $filters['tab']])) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
                @if ($filters['tab'] === 'resolved' && $tabCounts['resolved'])
                    <button type="submit" form="errors-clear" class="btn btn-ghost btn-sm text-error ml-auto">{{ __('Delete them') }}</button>
                @endif
            </form>

            @if ($filters['tab'] === 'changed' && $tabCounts['changed'])
                <p class="text-sm opacity-70 -mt-1">{{ __('The file each of these happened in was saved after the last time it happened — most likely fixed. Open one to check, then mark them dealt with; one that happens again comes back by itself.') }}</p>
            @endif

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">{{ __('Tick errors to mark several at once.') }}</p>
                <form method="POST" action="{{ route('admin.guard.errors.bulk') }}" x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    @if ($filters['tab'] === 'resolved')
                        <button name="action" value="reopen" class="btn btn-sm gap-1.5"><x-icon name="rotate-ccw" size="sm" /> {{ __('Back on the list') }}</button>
                    @else
                        <button name="action" value="resolve" class="btn btn-sm btn-primary gap-1.5"><x-icon name="circle-check" size="sm" /> {{ __('Dealt with') }}</button>
                    @endif
                    <button name="action" value="delete" class="btn btn-sm btn-ghost text-error gap-1.5"
                            data-confirm="{{ __('Delete the selected errors? A new occurrence starts a fresh row.') }}"><x-icon name="trash-2" size="sm" /> {{ __('Delete') }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="selected = @js(array_map('strval', $foundIds))">
                        {{ __('Select all :n found', ['n' => count($foundIds)]) }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @forelse ($errors as $error)
                @php $state = $error->codeState(); @endphp
                <div class="flex items-start gap-3 rounded-box border border-base-300 p-3 hover:bg-base-200/60"
                     :class="selected.includes('{{ $error->id }}') && 'bg-primary/5'">
                    <input type="checkbox" class="checkbox checkbox-sm mt-0.5" value="{{ $error->id }}" x-model="selected" aria-label="{{ $error->title() }}">
                    <a href="{{ route('admin.guard.errors.show', $error) }}" class="min-w-0 flex-1 flex items-start gap-3">
                        <span class="badge {{ $error->badge() }} badge-sm shrink-0 mt-0.5">{{ $error->level }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2 font-medium">
                                {{ $error->title() }}
                                @if ($state === 'changed')
                                    <span class="badge badge-ghost badge-sm gap-1"><x-icon name="file-pen" size="sm" /> {{ __('Code changed since') }}</span>
                                @elseif ($state === 'gone')
                                    <span class="badge badge-ghost badge-sm gap-1"><x-icon name="file-x" size="sm" /> {{ __('File no longer exists') }}</span>
                                @endif
                            </span>
                            <span class="block text-sm opacity-70 truncate">{{ $error->message }}</span>
                            <span class="flex flex-wrap gap-x-4 text-xs opacity-50 mt-0.5">
                                @if ($error->shortFile())
                                    <span class="font-mono">{{ $error->shortFile() }}@if ($error->originLine()):{{ $error->originLine() }}@endif</span>
                                @endif
                                @if ($url = $error->context['url'] ?? null)
                                    <span class="truncate max-w-md">{{ $error->context['method'] ?? 'GET' }} {{ \Illuminate\Support\Str::after($url, request()->getSchemeAndHttpHost()) ?: '/' }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="text-right shrink-0">
                            <span class="badge badge-ghost badge-sm">× {{ $error->count }}</span>
                            <span class="block text-xs opacity-50 mt-1" title="{{ $error->last_seen_at?->format('Y-m-d H:i') }}">{{ $error->last_seen_at?->diffForHumans() }}</span>
                        </span>
                    </a>
                </div>
            @empty
                <p class="text-sm opacity-60 py-6 text-center">
                    @if ($filters['q'] !== '' || $filters['level'] !== '')
                        {{ __('No errors match this search.') }}
                    @elseif ($filters['tab'] === 'open')
                        {{ __('Nothing has gone wrong. This is the good outcome.') }}
                    @else
                        {{ __('Nothing here.') }}
                    @endif
                </p>
            @endforelse

            {{ $errors->links() }}
        </div>
    </div>

    <form id="errors-clear" method="POST" action="{{ route('admin.guard.errors.clear_resolved') }}" class="hidden"
          data-confirm="{{ __('Delete every error marked as dealt with?') }}">
        @csrf
    </form>
@endsection
