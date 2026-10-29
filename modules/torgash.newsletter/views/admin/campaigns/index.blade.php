@extends('admin.layout')

@section('title', __('Campaigns'))
@section('title_hint', __('Letters to the subscribers: news, a sale, new arrivals. Each goes to every subscribed address or to those meeting its conditions, now or at a time, at the pace the mail server allows; opens, clicks and unsubscribes are counted per campaign.'))

@section('content')
    @php
        $campaignTabs = ['all' => __('All'), 'drafts' => __('Drafts'), 'scheduled' => __('Scheduled and sending'), 'sent' => __('Sent out')];
        $campaignStatus = [
            'draft' => ['badge-ghost', __('Draft')],
            'scheduled' => ['badge-warning', __('Scheduled')],
            'sending' => ['badge-info', __('Sending')],
            'sent' => ['badge-success', __('Sent out')],
            'cancelled' => ['badge-error', __('Stopped')],
        ];
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Campaigns') }}">
            @foreach ($campaignTabs as $tabKey => $tabLabel)
                <a href="{{ route('admin.newsletter.campaigns.index', array_filter(['tab' => $tabKey, 'q' => request('q')])) }}"
                   @class(['admin-tab', 'is-active' => $tab === $tabKey])>
                    {{ $tabLabel }} <span class="admin-tab-count">{{ $counts[$tabKey] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.newsletter.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="users" /> {{ __('Subscribers') }}</a>
            <a href="{{ route('admin.newsletter.campaigns.create') }}" class="btn btn-primary">+ {{ __('New campaign') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ request('q') }}" class="grow" placeholder="{{ __('Search by name') }}">
                </label>
            </form>

            @if ($campaigns->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="send" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ request('q') ? __('No campaigns match this search.') : __('No campaigns yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Campaign') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end">{{ __('Letters sent') }}</th>
                                <th class="text-end">{{ __('Opened') }}</th>
                                <th class="text-end">{{ __('Clicked') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($campaigns as $campaignRow)
                                @php [$campaignBadge, $campaignBadgeText] = $campaignStatus[$campaignRow->status] ?? ['badge-ghost', $campaignRow->status]; @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td>
                                        <a href="{{ route('admin.newsletter.campaigns.edit', $campaignRow) }}" class="font-medium link link-hover">{{ $campaignRow->name }}</a>
                                        <div class="text-xs opacity-50 truncate max-w-md">{{ $campaignRow->t('subject', admin_content_locale()) ?: '—' }}</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm {{ $campaignBadge }} whitespace-nowrap">{{ $campaignBadgeText }}</span>
                                        <div class="text-xs opacity-50 mt-1 whitespace-nowrap tabular-nums">
                                            @if ($campaignRow->status === 'scheduled' && $campaignRow->scheduled_at)
                                                {{ $campaignRow->scheduled_at->format('d.m.Y H:i') }}
                                            @elseif ($campaignRow->status === 'sending')
                                                {{ $campaignRow->sent + $campaignRow->failed }} / {{ $campaignRow->recipients }}
                                            @elseif ($campaignRow->finished_at)
                                                {{ $campaignRow->finished_at->format('d.m.Y H:i') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end tabular-nums">{{ $campaignRow->sent ?: '—' }}</td>
                                    <td class="text-end tabular-nums">{{ $campaignRow->rate('opened') !== null ? $campaignRow->rate('opened').'%' : '—' }}</td>
                                    <td class="text-end tabular-nums">{{ $campaignRow->rate('clicked') !== null ? $campaignRow->rate('clicked').'%' : '—' }}</td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.newsletter.campaigns.preview', $campaignRow) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the letter') }}" aria-label="{{ __('Open the letter') }}"><x-icon name="external-link" /></a>
                                            <a href="{{ route('admin.newsletter.campaigns.edit', $campaignRow) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            @if ($campaignRow->status !== 'sending')
                                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1"></span>
                                                <button form="campaign-delete-{{ $campaignRow->id }}" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $campaigns->links() }}
            @endif
        </div>
    </div>

    @foreach ($campaigns as $campaignRow)
        <form method="POST" id="campaign-delete-{{ $campaignRow->id }}" action="{{ route('admin.newsletter.campaigns.destroy', $campaignRow) }}"
              data-confirm="{{ __('Delete the campaign ":name" and its numbers?', ['name' => $campaignRow->name]) }}">@csrf @method('DELETE')</form>
    @endforeach
@endsection
