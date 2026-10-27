@extends('admin.layout')

@section('title', __('Invariants'))

{{--
    Things that must be true about this shop.

    Every statement is listed whether or not it is currently broken: a screen
    that shows only problems cannot tell "nothing is wrong" from "nothing was
    ever checked" — so "All" is the first tab and the tabs' counts are the
    summary. A statement is ONE line; what it found folds open under it, the
    way an audit entry's diff does. The verdicts are worked out in the
    controller, once, so a tab's count and its list cannot disagree.
--}}

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $tabs = [
            'all' => [__('All'), 'list-checks'],
            'broken' => [__('Broken'), 'circle-x'],
            'unchecked' => [__('Never checked'), 'circle-dashed'],
            'sound' => [__('Sound'), 'circle-check'],
            'hidden' => [__('Marked fine'), 'eye-off'],
        ];
        $tabUrl = fn (string $tab) => route('admin.guard.invariants.index', array_filter([
            'tab' => $tab === 'all' ? null : $tab,
            'q' => $filters['q'] ?: null,
            'module' => $filters['module'] ?: null,
        ]));
        // Folded open where the reader came to read findings: the "Broken" and
        // "Marked fine" tabs, or a search (which may have matched a finding).
        $unfold = in_array($filters['tab'], ['broken', 'hidden'], true) || $filters['q'] !== '';
        $anyFindings = $groups->flatten(1)->contains(fn ($s) => ($filters['tab'] === 'hidden' ? $s['hidden'] : $s['live'])->isNotEmpty());
    @endphp

    <div role="tablist" class="tabs tabs-border">
        @foreach ($tabs as $tabKey => [$tabLabel, $tabIcon])
            <a role="tab" href="{{ $tabUrl($tabKey) }}" @class(['tab gap-2', 'tab-active' => $filters['tab'] === $tabKey])>
                <x-icon :name="$tabIcon" size="sm" /> {{ $tabLabel }}
                <span @class([
                    'badge badge-sm',
                    'badge-error' => $tabKey === 'broken' && $counts['broken'],
                    'badge-warning' => $tabKey === 'unchecked' && $counts['unchecked'],
                    'badge-ghost' => ! (($tabKey === 'broken' && $counts['broken']) || ($tabKey === 'unchecked' && $counts['unchecked'])),
                ])>{{ $counts[$tabKey] }}</span>
            </a>
        @endforeach
    </div>

    {{-- One Alpine root: the bulk bar and every finding's box share `selected`. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    @if ($filters['tab'] !== 'all')
                        <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                    @endif
                    <label class="input input-sm w-full sm:w-80 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Statement, key or what it found…') }}">
                    </label>
                    <select name="module" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Whose statement') }}">
                        <option value="">{{ __('Every module') }}</option>
                        @foreach ($owners as $owner => $ownerLabel)
                            <option value="{{ $owner }}" @selected($filters['module'] === $owner)>{{ $ownerLabel }}</option>
                        @endforeach
                    </select>
                    @if ($filters['q'] !== '' || $filters['module'] !== '')
                        <a href="{{ route('admin.guard.invariants.index', array_filter(['tab' => $filters['tab'] === 'all' ? null : $filters['tab']])) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </form>

                <div class="ms-auto flex flex-wrap items-center gap-3">
                    <span class="text-xs opacity-50" title="{{ __('Everything is checked nightly as well; this is the same run.') }}">
                        @if ($lastRun)
                            {{ __('Last run :when.', ['when' => $lastRun->diffForHumans()]) }}
                        @else
                            {{ __('Never run yet.') }}
                        @endif
                    </span>
                    <button type="submit" form="invariants-run" class="btn btn-sm btn-primary gap-1.5">
                        <x-icon name="scan-search" size="sm" />
                        {{ __('Check now') }}
                    </button>
                </div>
            </div>

            @if ($anyFindings)
                <div class="min-h-12 flex items-center">
                    <p x-show="!selected.length" class="text-xs opacity-50">{{ __('Tick findings to mark several at once.') }}</p>
                    <form method="POST" action="{{ route('admin.guard.invariants.bulk') }}" x-show="selected.length" x-cloak
                          class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                        @csrf
                        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                        @if ($filters['tab'] === 'hidden')
                            <button name="action" value="unignore" class="btn btn-sm gap-1.5"><x-icon name="rotate-ccw" size="sm" /> {{ __('Back on the list') }}</button>
                        @else
                            <button name="action" value="ignore" class="btn btn-sm gap-1.5"><x-icon name="eye-off" size="sm" /> {{ __('This one is fine') }}</button>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                    </form>
                </div>
            @endif

            <div class="flex flex-col">
                @forelse ($groups as $owner => $statements)
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide opacity-60 pt-5 pb-1 first:pt-0">
                        {{ $statements->first()['group'] }}
                        <span class="font-normal normal-case tracking-normal opacity-70">{{ $statements->count() }}</span>
                    </div>

                    @foreach ($statements as $statement)
                        @php
                            $verdict = $statement['verdict'];
                            $state = $statement['state'];
                            $selfTest = $statement['key'] === \Torgash\Guard\Http\Admin\InvariantsController::SELF_TEST;
                            $rows = $filters['tab'] === 'hidden' ? $statement['hidden'] : $statement['live'];
                            // A sound statement with nothing under it is one
                            // line and nothing to open.
                            $foldable = $rows->isNotEmpty() || $state?->error || $selfTest
                                || in_array($verdict, ['broken', 'failed'], true);
                        @endphp

                        <details class="group/row border-t border-base-300" @if ($unfold && $foldable && (! $selfTest || in_array($verdict, ['broken', 'failed'], true))) open @endif>
                            <summary @class([
                                'list-none flex items-center gap-3 py-2 px-1 rounded hover:bg-base-200/60',
                                'cursor-pointer' => $foldable,
                                'pointer-events-none' => ! $foldable,
                            ]) @unless ($foldable) tabindex="-1" @endunless>
                                <span class="shrink-0">
                                    @switch ($verdict)
                                        @case ('unchecked') <x-icon name="circle-dashed" size="sm" class="opacity-40" /> @break
                                        @case ('failed') <x-icon name="triangle-alert" size="sm" class="text-(--torgash-warning-ink)" /> @break
                                        @case ('broken') <x-icon name="circle-x" size="sm" class="{{ $statement['warning'] ? 'text-(--torgash-warning-ink)' : 'text-error' }}" /> @break
                                        @default <x-icon name="circle-check" size="sm" class="text-success" />
                                    @endswitch
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span @class(['block', 'font-medium' => in_array($verdict, ['broken', 'failed'], true)])>{{ $statement['label'] }}</span>
                                    <span class="block text-xs font-mono opacity-40 truncate">{{ $statement['key'] }}</span>
                                </span>

                                <span class="shrink-0 text-right">
                                    @switch ($verdict)
                                        @case ('unchecked')
                                            <span class="badge badge-sm badge-ghost">{{ $statement['manual'] ? __('Only by hand') : __('Not checked yet') }}</span>
                                            @break
                                        @case ('failed')
                                            <span class="badge badge-sm badge-warning">{{ __('The check itself failed') }}</span>
                                            @break
                                        @case ('broken')
                                            @php $n = $statement['live']->count(); @endphp
                                            <span class="badge badge-sm {{ $statement['warning'] ? 'badge-warning' : 'badge-error' }}">
                                                {{ $state->truncated ? __('At least :n', ['n' => $n]) : trans_choice('{1}:n problem|[2,*]:n problems', $n, ['n' => $n]) }}
                                            </span>
                                            @break
                                        @default
                                            <span class="text-xs opacity-60">{{ __('Sound') }}</span>
                                    @endswitch
                                    @if ($statement['hidden']->isNotEmpty() && $filters['tab'] !== 'hidden')
                                        <span class="badge badge-sm badge-ghost gap-1" title="{{ __('Marked fine') }}"><x-icon name="eye-off" size="sm" /> {{ $statement['hidden']->count() }}</span>
                                    @endif
                                </span>

                                <span class="hidden md:block w-28 shrink-0 text-right text-xs opacity-50"
                                      @if ($state) title="{{ $state->last_run_at?->format('Y-m-d H:i') }} · {{ $state->duration_ms }} ms" @endif>
                                    {{ $state?->last_run_at?->diffForHumans() ?? '—' }}
                                </span>

                                {{-- Outside every form: the button names the one
                                     form below and carries the key as its own
                                     value, so no form is ever nested in a row. --}}
                                <button type="submit" form="invariants-run" name="key" value="{{ $statement['key'] }}"
                                        class="btn btn-ghost btn-sm btn-square shrink-0 pointer-events-auto"
                                        title="{{ $selfTest ? __('Run the self-test') : __('Check this one again') }}" aria-label="{{ $selfTest ? __('Run the self-test') : __('Check this one again') }}">
                                    <x-icon name="refresh-cw" size="sm" />
                                </button>

                                <x-icon name="chevron-down" size="sm" @class([
                                    'shrink-0 transition-transform group-open/row:rotate-180',
                                    'opacity-40' => $foldable,
                                    'invisible' => ! $foldable,
                                ]) />
                            </summary>

                            @if ($foldable)
                                <div class="pl-8 pr-1 pb-3 flex flex-col gap-3">
                                    {{-- "What to do about it" only where something
                                         IS wrong: advice under a statement that
                                         holds is advice about nothing. --}}
                                    @if ($statement['explain'] && in_array($verdict, ['broken', 'failed'], true) && ! $selfTest)
                                        <p class="text-sm opacity-70 max-w-4xl">{{ $statement['explain'] }}</p>
                                    @endif

                                    @if ($state?->error)
                                        <p class="text-xs font-mono opacity-60">{{ $state->error }}</p>
                                    @endif

                                    @if ($selfTest)
                                        @include('torgash.guard::admin.invariants._uploads', ['selfTestFailed' => $verdict === 'broken'])
                                    @endif

                                    @if ($rows->isNotEmpty())
                                        <div class="overflow-x-auto">
                                            <table class="table table-sm">
                                                <tbody>
                                                    @foreach ($rows as $finding)
                                                        <tr :class="selected.includes('{{ $finding->id }}') && 'bg-primary/5'">
                                                            <td class="w-8">
                                                                <input type="checkbox" class="checkbox checkbox-sm" value="{{ $finding->id }}" x-model="selected" aria-label="{{ $finding->titleText() }}">
                                                            </td>
                                                            <td class="font-medium whitespace-nowrap">
                                                                @if ($finding->url)
                                                                    <a href="{{ $finding->url }}" class="link link-hover">{{ $finding->titleText() }}</a>
                                                                @else
                                                                    {{ $finding->titleText() }}
                                                                @endif
                                                            </td>
                                                            <td class="text-sm opacity-70">{{ $finding->detailText() }}</td>
                                                            <td class="text-xs opacity-50 whitespace-nowrap" title="{{ __('First found :when', ['when' => $finding->first_seen_at?->format('Y-m-d H:i')]) }}">
                                                                {{ __('Found :when', ['when' => $finding->first_seen_at?->diffForHumans()]) }}
                                                            </td>
                                                            <td class="w-10 text-right">
                                                                @php $hiddenRow = (bool) $finding->ignored_at; @endphp
                                                                <button type="submit" form="invariants-one"
                                                                        formaction="{{ route($hiddenRow ? 'admin.guard.invariants.unignore' : 'admin.guard.invariants.ignore', $finding) }}"
                                                                        class="btn btn-ghost btn-sm btn-square"
                                                                        title="{{ $hiddenRow ? __('Back on the list') : __('This one is fine') }}" aria-label="{{ $hiddenRow ? __('Back on the list') : __('This one is fine') }}">
                                                                    <x-icon :name="$hiddenRow ? 'rotate-ccw' : 'eye-off'" size="sm" />
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif

                                    @if ($filters['tab'] !== 'hidden' && $statement['hidden']->isNotEmpty())
                                        <a href="{{ route('admin.guard.invariants.index', ['tab' => 'hidden', 'module' => $statement['owner']]) }}" class="text-xs link link-hover opacity-60 w-fit">
                                            {{ __(':n marked fine — show them', ['n' => $statement['hidden']->count()]) }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </details>
                    @endforeach
                @empty
                    <p class="text-sm opacity-60 py-6 text-center">
                        @if ($counts['all'] === 0 && $filters['q'] === '' && $filters['module'] === '')
                            {{ __('No module has said what must be true yet. A module declares its own statements by answering the guard.invariants filter.') }}
                        @elseif ($filters['q'] !== '' || $filters['module'] !== '')
                            {{ __('No statement matches this search.') }}
                        @elseif ($filters['tab'] === 'broken')
                            {{ __('Nothing is broken. This is the good outcome.') }}
                        @elseif ($filters['tab'] === 'hidden')
                            {{ __('Nothing is marked fine.') }}
                        @else
                            {{ __('Nothing here.') }}
                        @endif
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    <form id="invariants-run" method="POST" action="{{ route('admin.guard.invariants.run') }}" class="hidden">@csrf</form>
    <form id="invariants-one" method="POST" class="hidden">@csrf</form>
@endsection
