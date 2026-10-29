@extends('admin.layout')

@section('title', __('1C exchange'))

@php
    use Torgash\CommerceMl\Models\Link;

    $sections = [
        Link::KIND_PRICE_TYPE => __('Price types'),
        Link::KIND_FEATURE => __('Characteristics'),
    ];
@endphp

@section('content')
    @include('torgash.commerceml::admin._nav')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h3 class="font-semibold">{{ __('What to type into 1C') }}</h3>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td class="whitespace-nowrap opacity-70">{{ __('Address of the site') }}</td>
                            <td><code class="font-mono select-all">{{ url('/commerceml') }}</code></td>
                        </tr>
                        <tr>
                            <td class="whitespace-nowrap opacity-70">{{ __('User name') }}</td>
                            <td class="text-sm">{{ __('Anything at all — 1C insists on one, and the token below already says who this is.') }}</td>
                        </tr>
                        <tr>
                            <td class="whitespace-nowrap opacity-70">{{ __('Password') }}</td>
                            <td class="text-sm">
                                {{ __('An API token that may write, whose owner may set up this exchange.') }}
                                <a href="{{ route('admin.api.tokens') }}" class="link link-primary">{{ __('Issue one') }}</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="text-xs opacity-60">
                {{ __('A token rather than somebody’s password: this credential lives in a configuration file on an accounting machine for years, it can be revoked on its own, and it can never do more than its owner may.') }}
            </div>

            {{-- The other spelling of the same address. 1C's own instructions,
                 and every article written about them, say /1c_exchange.php — so
                 it has to answer, and on a typical server it only answers if a
                 file with that name exists. See LegacyEndpoint. --}}
            <div class="divider my-0"></div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <code class="font-mono text-sm select-all">{{ $legacy->url() }}</code>
                        @if ($legacy->isOurs())
                            <span class="badge badge-sm badge-success">{{ __('answering') }}</span>
                        @elseif ($legacy->exists())
                            <span class="badge badge-sm badge-warning">{{ __('occupied') }}</span>
                        @else
                            <span class="badge badge-sm badge-ghost">{{ __('not answering') }}</span>
                        @endif
                    </div>
                    <div class="text-xs opacity-60 mt-1">
                        @if ($legacy->isOurs())
                            {{ __('The address 1C’s own instructions hard-code works too, so a configuration set up for another engine needs nothing changed. It is a file in public/, written by this module and deleted when the module is removed.') }}
                        @elseif ($legacy->exists())
                            {{ __('A file of that name is already there and is not ours — the previous engine’s, most likely. It has been left exactly where it is; give 1C the address above until you are done with it.') }}
                        @else
                            {{ __('Only the address above works. Web servers answer 404 for a .php file that does not exist, so the address 1C’s instructions hard-code never reaches the shop — put the file back, or add a rewrite rule of your own.') }}
                        @endif
                    </div>
                </div>

                @if (! $legacy->exists() || $legacy->isOurs())
                    <form method="POST" action="{{ route('admin.commerceml.legacy') }}">
                        @csrf
                        <input type="hidden" name="action" value="{{ $legacy->isOurs() ? 'remove' : 'install' }}">
                        <button class="btn btn-sm btn-outline">
                            {{ $legacy->isOurs() ? __('Remove it') : __('Put it back') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- The language question, here rather than in the settings, because its
         options are the shop's own languages and a manifest cannot know them. --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-2 py-4">
            <form method="POST" action="{{ route('admin.commerceml.language') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('1C writes in this language') }}</legend>
                    <select name="content_locale" class="select select-bordered">
                        @foreach ($languages as $language)
                            <option value="{{ $language->code }}" @selected($contentLocale === $language->code)>
                                {{ $language->native_name }}
                            </option>
                        @endforeach
                    </select>
                </fieldset>
                <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                <div class="text-xs opacity-60 basis-full">
                    {{ __('1C sends one language and never says which. Names and descriptions land in this slot, and every other translation the shop has is left untouched.') }}
                </div>
            </form>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <div>
                <h3 class="font-semibold">{{ __('What 1C has sent, and what it is here') }}</h3>
                <div class="text-xs opacity-60">
                    {{ __('Empty until the first exchange: nobody can say what 1C’s price types correspond to before seeing what it calls them. Anything left unanswered is skipped, not guessed.') }}
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                @foreach ($sections as $kind => $label)
                    <a href="{{ route('admin.commerceml.matching', $kind) }}"
                       class="btn btn-outline btn-sm gap-2 {{ ($pending[$kind] ?? 0) > 0 ? 'btn-warning' : '' }}">
                        {{ $label }}
                        @if (($pending[$kind] ?? 0) > 0)
                            <span class="badge badge-sm badge-warning">{{ __(':n waiting', ['n' => $pending[$kind]]) }}</span>
                        @elseif (($counts[$kind] ?? 0) > 0)
                            <span class="badge badge-sm badge-ghost">{{ $counts[$kind] }}</span>
                        @else
                            <span class="text-xs opacity-50">{{ __('nothing yet') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            @if (($pending[Link::KIND_FEATURE_VALUE] ?? 0) > 0)
                <div class="text-xs opacity-60">
                    {{ __(':n characteristic values are waiting too — they are listed under their own characteristic.', ['n' => $pending[Link::KIND_FEATURE_VALUE]]) }}
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <div>
                <h3 class="font-semibold">{{ __('Orders') }}</h3>
                <div class="text-xs opacity-60">
                    {{ __('The other direction: which orders 1C is given, and what it is allowed to change when it answers. Off by default until the condition is stated — an unanswered question is not "send everything".') }}
                </div>
            </div>

            <div>
                <a href="{{ route('admin.commerceml.orders') }}" class="btn btn-outline btn-sm">
                    {{ __('Orders and what 1C may change') }}
                </a>
            </div>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            <h3 class="font-semibold">{{ __('Exchanges') }}</h3>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('What') }}</th>
                            <th>{{ __('Created') }}</th>
                            <th>{{ __('Updated') }}</th>
                            <th>{{ __('Skipped') }}</th>
                            <th>{{ __('Last word') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($exchanges as $exchange)
                            <tr>
                                <td class="whitespace-nowrap text-xs opacity-70">{{ $exchange->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="text-xs">{{ $exchange->kind === 'sale' ? __('Orders') : __('Catalogue') }}</td>
                                <td>{{ $exchange->created_count }}</td>
                                <td>{{ $exchange->updated_count }}</td>
                                <td class="{{ $exchange->skipped_count > 0 ? 'text-warning' : '' }}">{{ $exchange->skipped_count }}</td>
                                <td class="text-xs opacity-70">{{ $exchange->message }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center opacity-60 py-6">
                                    {{ __('1C has not called yet. Give it the address above and start an exchange from its side.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
