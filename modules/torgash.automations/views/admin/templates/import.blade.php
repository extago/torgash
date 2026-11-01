@extends('admin.layout')

@section('title', __('Import a rule'))

@section('content')
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Import a rule') }}</h1>
            <div class="text-xs opacity-60 mt-1">
                {{ __('A template carries the shape — the trigger, the conditions and the body — but never an address or a key. Those are yours, and it asks for them.') }}
            </div>
        </div>
        <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost btn-sm">{{ __('Back') }}</a>
    </div>

    @error('json')
        <div class="alert alert-error mb-4">{{ $message }}</div>
    @enderror

    @if (! $payload)
        @if ($presets)
            <div class="card bg-base-100 shadow-sm mb-4" id="ready">
                <div class="card-body gap-3">
                    <div>
                        <h2 class="font-semibold">{{ __('Ready-made rules') }}</h2>
                        <p class="text-xs opacity-60 mt-1">{{ __('The conditions and the message are written already: give the Telegram bot and chat, check the rule with "Test" and enable it.') }}</p>
                    </div>
                    <div class="grid gap-2 md:grid-cols-2">
                        @foreach ($presets as $slug => $preset)
                            <form method="POST" action="{{ route('admin.automations.import.review') }}"
                                  class="flex items-start justify-between gap-3 rounded-box border border-base-300 p-3">
                                @csrf
                                <input type="hidden" name="preset" value="{{ $slug }}">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $preset['name'] }}</div>
                                    <div class="text-xs opacity-60">{{ \Torgash\Automations\Triggers::label($preset['event']) }}</div>
                                    @if ($preset['about'] !== '')
                                        <p class="text-sm opacity-80 mt-1">{{ $preset['about'] }}</p>
                                    @endif
                                </div>
                                <button class="btn btn-sm shrink-0">{{ __('Use') }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.automations.import.review') }}" enctype="multipart/form-data"
                      class="flex flex-col gap-3">
                    @csrf
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Paste the template') }}</legend>
                        <textarea name="json" rows="12"
                                  class="textarea textarea-bordered w-full font-mono text-xs">{{ $json }}</textarea>
                    </fieldset>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('…or choose a file') }}</legend>
                        <input type="file" name="file" accept="application/json,.json" class="file-input file-input-bordered">
                    </fieldset>

                    <div>
                        <button class="btn btn-primary btn-sm">{{ __('Read it') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body gap-4">
                <div>
                    <div class="text-sm opacity-60">{{ __('Rule') }}</div>
                    <div class="text-lg font-medium">{{ $payload['name'] ?? __('Imported rule') }}</div>
                    @if (! empty($payload['about']) && is_string($payload['about']))
                        <p class="text-sm opacity-80">{{ $payload['about'] }}</p>
                    @endif
                    <div class="text-xs opacity-60 mt-1">
                        {{ \Torgash\Automations\Triggers::label($payload['event'] ?? '') }}
                        @if (! empty($payload['delay_minutes']))
                            · {{ __('after :n min', ['n' => $payload['delay_minutes']]) }}
                        @endif
                        · {{ trans_choice(':count condition|:count conditions', count($payload['conditions'] ?? [])) }}
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.automations.import.store') }}" class="flex flex-col gap-3">
                    @csrf
                    <input type="hidden" name="json" value="{{ $json }}">

                    @php $knownBot = \Torgash\Automations\RuleTemplate::knownBot(); @endphp
                    @if ($knownBot && collect($needs)->contains(fn ($need) => str_ends_with($need['path'], '.token')))
                        <p class="text-sm opacity-70">{{ __('Leave the bot token and the chat empty to use the ones your other Telegram rules use.') }}</p>
                    @endif
                    @forelse ($needs as $need)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $need['label'] }}
                                @if ($need['secret'])
                                    <span class="badge badge-ghost badge-sm ml-1">{{ __('not included in the template') }}</span>
                                @endif
                            </legend>
                            <input name="answers[{{ $need['path'] }}]"
                                   type="{{ $need['secret'] ? 'password' : 'text' }}"
                                   autocomplete="off"
                                   class="input input-bordered w-full font-mono">
                        </fieldset>
                    @empty
                        <p class="text-sm opacity-60">{{ __('Nothing to fill in.') }}</p>
                    @endforelse

                    <div class="flex gap-2">
                        <button class="btn btn-primary btn-sm">{{ __('Import') }}</button>
                        <a href="{{ route('admin.automations.import') }}" class="btn btn-ghost btn-sm">{{ __('Start over') }}</a>
                    </div>
                </form>

                <details class="text-sm">
                    <summary class="cursor-pointer opacity-60 hover:opacity-100 w-fit">{{ __('What it will send') }}</summary>
                    @foreach ($payload['actions'] ?? [] as $action)
                        @if (($action['body'] ?? $action['text'] ?? '') !== '')
                            <pre class="bg-base-200 p-2 rounded mt-2 text-xs whitespace-pre-wrap">{{ $action['body'] ?? $action['text'] }}</pre>
                        @endif
                    @endforeach
                </details>
            </div>
        </div>
    @endif
@endsection
