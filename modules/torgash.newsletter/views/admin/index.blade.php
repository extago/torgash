@extends('admin.layout')

@section('title', __('Subscribers'))
@section('title_hint', __('Who wants the shop\'s news and offers. An address counts once its owner has followed the link in the confirmation letter. Letters to them are Campaigns; the list also goes to a mailing service as a CSV.'))

@section('content')
    @php
        $subscriberTabs = [
            'subscribed' => __('Subscribed'),
            'pending' => __('Waiting for confirmation'),
            'unsubscribed' => __('Unsubscribed'),
            'all' => __('All'),
        ];
        // key => [words, icon]
        $subscriberSources = [
            'block' => [__('Subscription block'), 'mail-plus'],
            'cart' => [__('Basket page'), 'shopping-cart'],
            'checkout' => [__('Checkout'), 'credit-card'],
            'account' => [__('Account'), 'user-round'],
            'import' => [__('Import'), 'upload'],
            'admin' => [__('Admin'), 'shield'],
        ];
        $subscriberStatus = [
            'subscribed' => ['badge-success', __('Subscribed')],
            'pending' => ['badge-warning', __('Waiting for confirmation')],
            'unsubscribed' => ['badge-ghost', __('Unsubscribed')],
        ];
        $subscriberFilter = array_filter(['q' => request('q'), 'source' => request('source')]);
        $subscriberTotal = $counts->sum();
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- One Alpine root over the tabs' row and the card: the Import button up
         there opens the form down here. `contents`, so both stay the page's
         own blocks. --}}
    <div class="contents" x-data="{ importing: {{ $errors->has('file') ? 'true' : 'false' }} }">
    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search and the source. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Subscribers') }}">
            @foreach ($subscriberTabs as $tabKey => $tabLabel)
                <a href="{{ route('admin.newsletter.index', ['tab' => $tabKey] + $subscriberFilter) }}"
                   @class(['admin-tab', 'is-active' => $tab === $tabKey])>
                    {{ $tabLabel }} <span class="admin-tab-count">{{ $tabKey === 'all' ? $subscriberTotal : ($counts[$tabKey] ?? 0) }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.newsletter.campaigns.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="send" /> {{ __('Campaigns') }}</a>
            <button type="button" class="btn btn-ghost gap-1.5" x-on:click="importing = !importing" :class="importing && 'btn-active'">
                <x-icon name="upload" /> {{ __('Import') }}
            </button>
            <a href="{{ route('admin.newsletter.export', ['tab' => $tab] + $subscriberFilter) }}" class="btn gap-1.5">
                <x-icon name="download" /> {{ __('Download CSV') }}
            </a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            {{-- The list at a glance: how big, and how it moved this month. --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Subscribed') }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ $counts['subscribed'] ?? 0 }}</div>
                </div>
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Waiting for confirmation') }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ $counts['pending'] ?? 0 }}</div>
                </div>
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Joined in 30 days') }}</div>
                    <div class="text-2xl font-semibold tabular-nums text-success">+{{ $joined }}</div>
                </div>
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Left in 30 days') }}</div>
                    <div class="text-2xl font-semibold tabular-nums {{ $left ? 'text-error' : '' }}">{{ $left ? '−'.$left : '0' }}</div>
                </div>
            </div>

            {{-- Addresses brought from elsewhere: opened on demand, full-size like every form field. --}}
            <form method="POST" action="{{ route('admin.newsletter.import') }}" enctype="multipart/form-data"
                  x-show="importing" x-cloak class="rounded-box border border-base-300 bg-base-200/40 p-4 flex flex-col gap-3"
                  data-confirm="{{ __('Add these addresses as subscribed? Only addresses whose owners agreed elsewhere — a list bought or collected without consent gets the shop\'s letters marked as spam.') }}">
                @csrf
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('CSV file') }}</legend>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required class="file-input file-input-bordered w-full sm:w-96" aria-label="{{ __('CSV file') }}">
                        <button class="btn btn-primary gap-1.5"><x-icon name="upload" /> {{ __('Import') }}</button>
                    </div>
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('One address per line, or the first column of a CSV. They are added as subscribed; somebody who unsubscribed here stays unsubscribed.') }}</p>
                </fieldset>
            </form>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ request('q') }}" class="grow" placeholder="{{ __('Search by email') }}">
                </label>
                <select name="source" class="select select-sm w-auto" aria-label="{{ __('Where from') }}" x-on:change="$el.form.requestSubmit()">
                    <option value="">{{ __('From anywhere') }}</option>
                    @foreach ($subscriberSources as $sourceKey => [$sourceLabel])
                        <option value="{{ $sourceKey }}" @selected(request('source') === $sourceKey)>{{ $sourceLabel }}</option>
                    @endforeach
                </select>
                @if ($subscriberFilter)
                    <a href="{{ route('admin.newsletter.index', ['tab' => $tab]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($subscribers->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="mail" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $subscriberFilter ? __('Nobody matches this search.') : __('Nobody here yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Email') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Where from') }}</th>
                                <th>{{ __('Since') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subscribers as $subscriberRow)
                                @php
                                    [$statusClass, $statusLabel] = $subscriberStatus[$subscriberRow->status] ?? ['badge-ghost', $subscriberRow->status];
                                    [$sourceLabel, $sourceIcon] = $subscriberSources[$subscriberRow->source] ?? [$subscriberRow->source, 'circle-help'];
                                    $subscriberSince = $subscriberRow->status === 'unsubscribed'
                                        ? $subscriberRow->unsubscribed_at
                                        : ($subscriberRow->confirmed_at ?? $subscriberRow->created_at);
                                @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full bg-base-200 grid place-items-center text-sm font-semibold uppercase shrink-0 opacity-80">{{ mb_substr($subscriberRow->email, 0, 1) }}</span>
                                            <div class="min-w-0">
                                                <div @class(['font-medium truncate', 'opacity-60' => $subscriberRow->status === 'unsubscribed'])>{{ $subscriberRow->email }}</div>
                                                @if ($subscriberRow->locale || $subscriberRow->user_id)
                                                    <div class="text-xs opacity-50 flex items-center gap-2">
                                                        @if ($subscriberRow->locale)<span class="uppercase">{{ $subscriberRow->locale }}</span>@endif
                                                        @if ($subscriberRow->user_id)<span class="inline-flex items-center gap-1"><x-icon name="user-round-check" size="sm" /> {{ __('Has an account') }}</span>@endif
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge badge-sm {{ $statusClass }} whitespace-nowrap">{{ $statusLabel }}</span></td>
                                    <td>
                                        <span class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap" title="{{ $subscriberRow->consent_text }}">
                                            <x-icon :name="$sourceIcon" size="sm" class="opacity-50" /> {{ $sourceLabel }}
                                        </span>
                                    </td>
                                    <td class="text-sm whitespace-nowrap tabular-nums opacity-70">{{ $subscriberSince?->format('d.m.Y H:i') }}</td>
                                    <td class="text-end">
                                        <button form="subscriber-delete-{{ $subscriberRow->id }}" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove from the list') }}" aria-label="{{ __('Remove from the list') }}">
                                            <x-icon name="trash-2" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $subscribers->links() }}
            @endif
        </div>
    </div>
    </div>

    @foreach ($subscribers as $subscriberRow)
        <form method="POST" id="subscriber-delete-{{ $subscriberRow->id }}" action="{{ route('admin.newsletter.destroy', $subscriberRow) }}"
              data-confirm="{{ __('Remove :email from the list? To keep them from being added again, they should unsubscribe instead.', ['email' => $subscriberRow->email]) }}">@csrf @method('DELETE')</form>
    @endforeach
@endsection
