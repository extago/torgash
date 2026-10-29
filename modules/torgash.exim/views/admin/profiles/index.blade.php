@extends('admin.layout')

@section('title', __('Export profiles'))
@section('title_hint', __('A profile is one export: which data, which columns, which format. Download it now, run it on a schedule, or give a partner its feed address — a price list for a wholesaler, a file for a marketplace.'))

@section('content')
    @php $locale = admin_content_locale(); @endphp

    @include('torgash.exim::admin._nav', ['eximScreen' => 'export'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Export profiles') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled'), 'scheduled' => __('Updated by themselves')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.exim.profiles.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => $filters['status'] === $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.exim.profiles.create') }}" class="btn btn-primary">+ {{ __('New profile') }}</a>
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
                    <a href="{{ route('admin.exim.profiles.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick profiles to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.exim.profiles.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected export profiles? Their feed addresses stop working.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($profiles->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="download" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No export profiles match this search.') : __('No export profiles yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($profiles->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $profiles->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Format') }}</th>
                                <th>{{ __('Schedule') }}</th>
                                <th>{{ __('Last run') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($profiles as $profile)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $profile->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $profile->id }}" x-model="selected"
                                               aria-label="{{ $profile->t('name', $locale) }}">
                                    </td>
                                    <td class="min-w-48">
                                        <a href="{{ route('admin.exim.profiles.edit', $profile) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $profile->enabled])>{{ $profile->t('name', $locale) }}</a>
                                        <div class="text-xs opacity-50">
                                            {{ __($entities[$profile->entity] ?? $profile->entity) }}
                                            @if ($profile->mode === 'template') · {{ __('own template') }} @endif
                                            @if ($profile->requiresBasicAuth()) · <x-icon name="lock" size="sm" class="inline align-text-bottom" /> @endif
                                        </div>
                                    </td>
                                    <td><span class="badge badge-sm badge-ghost font-mono">{{ strtoupper($profile->format) }}</span></td>
                                    @php
                                        $rowCron = $profile->cronExpression();
                                        $rowWhen = implode(' · ', array_filter([
                                            $rowCron === null ? null : (isset(\Torgash\Exim\Models\EximProfile::SCHEDULES[$rowCron]) ? __(\Torgash\Exim\Models\EximProfile::SCHEDULES[$rowCron]) : $rowCron),
                                            $profile->rebuild_on_change ? __('on a change') : null,
                                        ]));
                                    @endphp
                                    <td class="text-sm">@if ($rowWhen !== '') {{ $rowWhen }} @else <span class="opacity-40">{{ __('on demand') }}</span> @endif</td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">{{ $profile->last_run_at?->diffForHumans() ?? __('never') }}</td>
                                    @include('torgash.exim::admin.profiles._status', ['profile' => $profile])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.exim.profiles.run', $profile) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Download now') }}" aria-label="{{ __('Download now') }}"><x-icon name="download" /></button>
                                            </form>
                                            <a href="{{ route('admin.exim.profiles.edit', $profile) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.exim.profiles.destroy', $profile) }}" class="inline"
                                                  data-confirm="{{ __('Delete this export profile? Its feed address stops working.') }}">
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
    </div>
@endsection
