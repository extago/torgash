{{-- A campaign's numbers: how far it went and what the readers did. Shares
     are of the letters SENT. Named campaign*: an @include hands a partial
     every variable its caller has. --}}
@php
    $campaignDone = $campaign->sent + $campaign->failed;
    $campaignShare = $campaign->recipients > 0 ? (int) floor(100 * $campaignDone / $campaign->recipients) : 0;
@endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <div class="flex items-baseline justify-between gap-3 flex-wrap">
            <h3 class="font-semibold">{{ __('Numbers') }}</h3>
            <span class="text-xs opacity-60">
                @if ($campaign->started_at){{ __('Started :date', ['date' => $campaign->started_at->format('d.m.Y H:i')]) }}@endif
                @if ($campaign->finished_at) · {{ __('finished :date', ['date' => $campaign->finished_at->format('d.m.Y H:i')]) }}@endif
            </span>
        </div>

        @if ($campaign->status === 'sending')
            <div class="flex items-center gap-3">
                <progress class="progress progress-primary w-full" value="{{ $campaignDone }}" max="{{ max(1, $campaign->recipients) }}"></progress>
                <span class="text-sm tabular-nums whitespace-nowrap">{{ $campaignDone }} / {{ $campaign->recipients }}</span>
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            @foreach ([
                [__('Letters sent'), $campaign->sent, $campaign->recipients ? __('of :count', ['count' => $campaign->recipients]) : null, ''],
                [__('Opened'), $campaign->opened, $campaign->rate('opened') !== null ? $campaign->rate('opened').'%' : null, 'text-success'],
                [__('Clicked'), $campaign->clicked, $campaign->rate('clicked') !== null ? $campaign->rate('clicked').'%' : null, 'text-primary'],
                [__('Unsubscribed'), $campaign->unsubscribed, $campaign->rate('unsubscribed') !== null ? $campaign->rate('unsubscribed').'%' : null, $campaign->unsubscribed ? 'text-warning' : ''],
                [__('Not delivered'), $campaign->failed, null, $campaign->failed ? 'text-error' : ''],
            ] as [$campaignStatLabel, $campaignStatValue, $campaignStatNote, $campaignStatClass])
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ $campaignStatLabel }}</div>
                    <div class="text-2xl font-semibold tabular-nums {{ $campaignStatClass }}">{{ $campaignStatValue }}</div>
                    @if ($campaignStatNote)<div class="text-xs opacity-60 tabular-nums">{{ $campaignStatNote }}</div>@endif
                </div>
            @endforeach
        </div>
        <p class="text-xs opacity-50">{{ __('Opens are counted by a picture in the letter: a mail client that does not show pictures is not counted until its reader clicks a link.') }}</p>

        @if ($topLinks->isNotEmpty())
            <div>
                <div class="text-sm font-medium mb-1">{{ __('What they clicked') }}</div>
                <table class="table">
                    <tbody>
                        @foreach ($topLinks as $campaignLink)
                            <tr>
                                <td class="max-w-0 w-full"><a href="{{ $campaignLink->url }}" target="_blank" rel="noopener noreferrer" class="link link-hover truncate block">{{ $campaignLink->url }}</a></td>
                                <td class="text-end tabular-nums whitespace-nowrap">{{ $campaignLink->clicks }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
