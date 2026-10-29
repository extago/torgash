@extends('admin.layout')

@section('title', __('Automations'))
@section('title_hint', __('When something happens, wait if you like, check the conditions, then act. Rules run in the queue, so nothing here slows the shop down.'))

@section('content')
    @php
        use Torgash\Automations\Actions\HasFields;
        use Torgash\Automations\Jobs\RunRule;
        use Torgash\Automations\Subjects\Subjects;
        use Torgash\Automations\Triggers;

        $subjects = Subjects::all();
        $actionClasses = RunRule::actions();
        $actionIcons = ['webhook' => 'webhook', 'telegram' => 'send', 'set_status' => 'flag', 'set_manager' => 'user-round'];
        // Declared fields once per type, not per row: they read a table each.
        $actionFields = [];
        foreach ($actionClasses as $type => $class) {
            $actionFields[$type] = is_subclass_of($class, HasFields::class) ? $class::fields() : [];
        }
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Automations') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.automations.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.automations.runs') }}" class="btn btn-ghost gap-1.5">
                <x-icon name="list-checks" size="sm" /> {{ __('Run log') }}
                @if ($failedToday)
                    <span class="badge badge-sm badge-error" title="{{ __('Failed in the last 24 hours') }}">{{ $failedToday }}</span>
                @endif
            </a>
            <a href="{{ route('admin.automations.import') }}" class="btn btn-ghost gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Import') }}</a>
            <a href="{{ route('admin.automations.create') }}" class="btn btn-primary">+ {{ __('New rule') }}</a>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['status'])
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name…') }}">
                </label>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick rules to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.automations.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected rules? Their run log stays.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($rules->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="zap" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No rules match this search.') : __('No rules yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($rules->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $rules->count() }}">
                                </th>
                                <th>{{ __('Rule') }}</th>
                                <th>{{ __('Only when') }}</th>
                                <th>{{ __('Then') }}</th>
                                <th>{{ __('Last run') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                @php
                                    $last = $lastRuns[$rule->id] ?? null;
                                    $ruleSubject = $subjects[$rule->subjectKey()] ?? null;
                                @endphp
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $rule->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $rule->id }}" x-model="selected" aria-label="{{ $rule->name }}">
                                    </td>
                                    <td class="min-w-56">
                                        <a href="{{ route('admin.automations.edit', $rule) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $rule->enabled])>{{ $rule->name }}</a>
                                        <div class="text-xs opacity-60 mt-0.5 flex items-center gap-1">
                                            <x-icon name="zap" size="sm" class="opacity-60" /> {{ Triggers::label($rule->event) }}
                                            @if ($ruleSubject)
                                                <span class="badge badge-sm badge-ghost">{{ __($ruleSubject::label()) }}</span>
                                            @endif
                                        </div>
                                        @if ($rule->fields)
                                            <div class="text-xs opacity-50">
                                                {{ __('only') }}: {{ implode(', ', array_map(fn ($f) => Triggers::fields()[$f] ?? $f, $rule->fields)) }}
                                            </div>
                                        @endif
                                        @if ($rule->delay_minutes)
                                            <div class="text-xs opacity-50 flex items-center gap-1">
                                                <x-icon name="timer" size="sm" /> {{ __('after :n min', ['n' => $rule->delay_minutes]) }}
                                            </div>
                                        @endif
                                    </td>
                                    {{-- Conditions in plain words, the same idea as the
                                         shipping methods screen: a rule that "did not
                                         fire" is visibly a rule with requirements. --}}
                                    <td class="text-xs">
                                        @forelse ($rule->conditions as $condition)
                                            <div>{{ $ruleSubject?->describe($condition) ?? $condition->kind }}</div>
                                        @empty
                                            <span class="opacity-40">{{ __('always') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-xs">
                                        @forelse ($rule->actions as $action)
                                            <div class="flex items-center gap-1.5 max-w-xs">
                                                <x-icon :name="$actionIcons[$action->type] ?? 'zap'" size="sm" class="opacity-60 shrink-0" />
                                                <span class="truncate">
                                                    @switch($action->type)
                                                        @case('webhook')
                                                            <span class="font-mono" title="{{ $action->config('url') }}">{{ $action->config('method', 'POST') }} {{ $action->config('url') }}</span>
                                                            @break
                                                        @case('telegram')
                                                            {{ __('Telegram') }}
                                                            @if ($action->config('chat_id'))
                                                                <span class="opacity-60">· {{ $action->config('chat_id') }}</span>
                                                            @endif
                                                            @if (! $action->config('token') || ! $action->config('chat_id'))
                                                                <span class="badge badge-sm badge-warning whitespace-nowrap">{{ __('not set up') }}</span>
                                                            @endif
                                                            @break
                                                        @default
                                                            {{ isset($actionClasses[$action->type]) ? __($actionClasses[$action->type]::label()) : $action->type }}
                                                            @foreach ($actionFields[$action->type] ?? [] as $fieldName => $field)
                                                                → {{ $field['options'][(string) $action->config($fieldName, '')] ?? $action->config($fieldName, '—') }}
                                                            @endforeach
                                                    @endswitch
                                                </span>
                                            </div>
                                        @empty
                                            <span class="badge badge-sm badge-warning">{{ __('No actions') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-xs whitespace-nowrap">
                                        @if ($last)
                                            <a href="{{ route('admin.automations.runs', ['rule' => $rule->id]) }}" class="inline-flex items-center gap-1.5 hover:underline" title="{{ $last->summary }}">
                                                <span class="badge badge-sm {{ $last->badge() }}">{{ __($last->status) }}</span>
                                                <span class="opacity-60">{{ $last->created_at?->diffForHumans() }}</span>
                                            </a>
                                        @else
                                            <span class="opacity-40">{{ __('never') }}</span>
                                        @endif
                                    </td>
                                    @include('torgash.automations::admin.rules._status', ['rule' => $rule])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Test and export stand before edit; delete is set
                                             apart, as on every list. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.automations.test', $rule) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Test on the latest order') }}" aria-label="{{ __('Test on the latest order') }}">
                                                    <x-icon name="play" />
                                                </button>
                                            </form>
                                            <a href="{{ route('admin.automations.export', $rule) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Export') }}" aria-label="{{ __('Export') }}">
                                                <x-icon name="download" />
                                            </a>
                                            <a href="{{ route('admin.automations.edit', $rule) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.automations.destroy', $rule) }}" class="inline"
                                                  data-confirm="{{ __('Delete this rule?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
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
    </div>
@endsection
