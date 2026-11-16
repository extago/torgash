@extends('admin.layout')

@section('title', __('Telegram buttons'))
@section('title_hint', __('A rule\'s Telegram message can carry buttons — "Take it", "Shipped", "Called". Whoever presses one is found by their Telegram account, must be a staff member with the right to do the same in the admin, and the history says who did it. Here each staff member links their own Telegram, and you choose how the presses reach the shop.'))
@section('title_actions')
    @can('automations.manage')
        <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="arrow-left" size="sm" /> {{ __('Automations') }}</a>
    @endcan
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-2 items-start">
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="user-check" class="opacity-60" /> {{ __('Your Telegram') }}</h3>

                @if ($mine)
                    <p class="text-sm flex items-center gap-2">
                        <span class="badge badge-sm badge-success">{{ __('Linked') }}</span>
                        {{ $mine->telegram_name ?: '#'.$mine->telegram_id }}
                    </p>
                @else
                    <p class="text-sm opacity-70">{{ __('Not linked yet: a button you press answers that it does not know you.') }}</p>
                @endif

                @if ($code)
                    <div class="rounded-box border border-base-300 p-4 flex flex-col gap-2">
                        <div class="text-xs opacity-60">{{ __('Send this to the bot — in a private chat with it or in the group it writes to — within :minutes minutes:', ['minutes' => \Torgash\Automations\Telegram\Updates::LINK_MINUTES]) }}</div>
                        <div class="font-mono text-lg select-all">/link {{ $code }}</div>
                        @foreach ($bots as $bot)
                            @if ($bot->name() !== '')
                                <a href="https://t.me/{{ ltrim($bot->name(), '@') }}?start={{ $code }}" target="_blank" rel="noopener" class="link text-sm">
                                    {{ __('…or open :bot and press Start', ['bot' => $bot->name()]) }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.automations.telegram.code') }}">
                        @csrf
                        <button class="btn btn-sm btn-primary">{{ $mine ? __('Link another account') : __('Get a code') }}</button>
                    </form>
                    @if ($mine)
                        <form method="POST" action="{{ route('admin.automations.telegram.unlink', auth()->id()) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-ghost text-error">{{ __('Unlink') }}</button>
                        </form>
                    @endif
                </div>

                @if ($bots->isEmpty())
                    <p class="text-xs opacity-60">{{ __('No enabled rule sends buttons yet, so no bot is listening: add buttons to a rule\'s Telegram message first.') }}</p>
                @endif
            </div>
        </section>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="radio" class="opacity-60" /> {{ __('How presses reach the shop') }}</h3>
                <p class="text-xs opacity-60 -mt-2">{{ __('"The shop asks" works anywhere and answers within a second or two while the scheduler runs. "Telegram calls" is instant, but the shop\'s address must be public and https.') }}</p>

                @forelse ($bots as $bot)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm border-t border-base-300 pt-3">
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ $bot->name() ?: __('Bot') .' #'.$bot->id }}</div>
                            <div class="text-xs opacity-60">
                                {{ $bot->mode === \Torgash\Automations\Telegram\Bot::WEBHOOK ? __('Telegram calls the shop') : __('The shop asks Telegram') }}
                                · {{ $bot->heard_at ? __('last heard :when', ['when' => $bot->heard_at->diffForHumans()]) : __('nothing heard yet') }}
                            </div>
                        </div>
                        <form method="POST" action="{{ route('admin.automations.telegram.mode', $bot) }}" @cannot('automations.manage') hidden @endcannot>
                            @csrf
                            @if ($bot->mode === \Torgash\Automations\Telegram\Bot::WEBHOOK)
                                <input type="hidden" name="mode" value="poll">
                                <button class="btn btn-sm">{{ __('Let the shop ask') }}</button>
                            @else
                                <input type="hidden" name="mode" value="webhook">
                                <button class="btn btn-sm" @disabled(! $reachable) title="{{ $reachable ? '' : __('The shop\'s address is not public https.') }}">{{ __('Let Telegram call') }}</button>
                            @endif
                        </form>
                    </div>
                @empty
                    <p class="text-sm opacity-60">{{ __('No bot sends buttons yet.') }}</p>
                @endforelse

                <p class="text-xs opacity-60">{{ __('Send /id to a bot in any chat and it answers with that chat\'s id — the number a rule\'s "Chat id" wants.') }}</p>
            </div>
        </section>

        @if ($linked->isNotEmpty() && auth()->user()->can('automations.manage'))
            <section class="card bg-base-100 border border-base-300 xl:col-span-2">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Who is linked') }}</h3>
                    @foreach ($linked as $link)
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate">{{ $link['user']->name }} <span class="opacity-60">· {{ $link['telegram'] ?: '—' }}</span></span>
                            <form method="POST" action="{{ route('admin.automations.telegram.unlink', $link['user']->id) }}"
                                  data-confirm="{{ __('Unlink this Telegram account? Buttons pressed from it will do nothing.') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Unlink') }}" aria-label="{{ __('Unlink') }}"><x-icon name="trash-2" size="sm" /></button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
