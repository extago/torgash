@extends('admin.layout')

@section('title', __('Code delivery'))

{{-- The HTTP requests that carry a verification code (CodeChannelsController). --}}
@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Besides email, a code can travel by any HTTP request: an SMS gateway, a messenger\'s API. Pick a ready filling or write your own; the customer is offered every switched-on channel that can reach their address, in this order. Every code sent is on the Verification codes screen.') }}
                </p>
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-primary btn-sm">+ {{ __('New channel') }}</div>
                    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box border border-base-300 shadow z-10 w-60 p-2">
                        @foreach ($presets as $presetKey => $presetName)
                            <li><a href="{{ route('admin.automations.channels.create', ['preset' => $presetKey]) }}">{{ __($presetName) }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Channel') }}</th>
                            <th>{{ __('Delivers to') }}</th>
                            <th>{{ __('Countries') }}</th>
                            <th class="text-right">{{ __('Today') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($channels as $channel)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.automations.channels.edit', $channel) }}" class="link link-hover font-medium">{{ $channel->name }}</a>
                                    <div class="text-xs opacity-60 font-mono break-all">{{ \Illuminate\Support\Str::limit($channel->url, 70) }}</div>
                                </td>
                                <td class="text-sm">{{ collect($channel->kinds)->map(fn ($kind) => $kind === 'phone' ? __('Phone') : 'Email')->implode(', ') }}</td>
                                <td class="text-sm font-mono">{{ $channel->countryList() ? implode(', ', $channel->countryList()) : __('any country') }}</td>
                                <td class="text-right tabular-nums text-sm">{{ $today[$channel->id] }} / {{ $channel->daily_limit }}</td>
                                @include('torgash.automations::admin.code-channels._status', ['channel' => $channel])
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.automations.channels.edit', $channel) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300"></span>
                                        <form method="POST" action="{{ route('admin.automations.channels.destroy', $channel) }}"
                                              data-confirm="{{ __('Delete :name?', ['name' => $channel->name]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center opacity-60 py-8">{{ __('Only email so far. Add a channel to send codes by SMS or a messenger.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
