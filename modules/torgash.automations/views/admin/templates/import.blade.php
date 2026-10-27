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

                    @forelse ($needs as $need)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $need['label'] }}
                                @if ($need['secret'])
                                    <span class="badge badge-ghost badge-xs ml-1">{{ __('not included in the template') }}</span>
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
                        <pre class="bg-base-200 p-2 rounded mt-2 text-xs whitespace-pre-wrap">{{ $action['body'] ?? '' }}</pre>
                    @endforeach
                </details>
            </div>
        </div>
    @endif
@endsection
