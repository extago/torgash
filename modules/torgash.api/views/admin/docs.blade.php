@extends('admin.layout')

@section('title', __('API reference'))

@php
    use Torgash\Api\OpenApi;

    $openApi = app(OpenApi::class);

    /** The colour an HTTP verb is read by everywhere. */
    $verbClass = [
        'get' => 'badge-info',
        'post' => 'badge-success',
        'patch' => 'badge-warning',
        'put' => 'badge-warning',
        'delete' => 'badge-error',
    ];

    /** A `$ref` followed home, so the page can print the shape it points at. */
    $resolve = function (array $schema) use ($document) {
        if (! isset($schema['$ref'])) {
            return $schema;
        }

        $name = basename($schema['$ref']);

        return $document['components']['schemas'][$name] ?? [];
    };

    $anchor = fn (string $method, string $path) => $method.'-'.trim(preg_replace('~[^a-z0-9]+~i', '-', $path), '-');
@endphp

@section('content')
    @include('torgash.api::admin._nav', ['apiScreen' => 'docs'])

    <div x-data="apiReference()" class="flex items-start gap-6">
        {{-- The map. Sticky, because a reference is read by jumping around it. --}}
        <aside class="hidden xl:block w-64 shrink-0 sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto">
            <ul class="menu menu-sm bg-base-100 border border-base-300 rounded-box w-full">
                @foreach ($groups as $section => $tags)
                    <li class="menu-title text-xs uppercase tracking-wider">{{ $section }}</li>
                    @foreach ($tags as $tag => $operations)
                        <li>
                            <details open>
                                <summary class="font-medium">{{ $tag }}</summary>
                                <ul>
                                    @foreach ($operations as $operation)
                                        <li>
                                            <a href="#{{ $anchor($operation['method'], $operation['path']) }}" class="gap-2">
                                                <span class="badge badge-xs {{ $verbClass[$operation['method']] ?? '' }} font-mono uppercase w-12 shrink-0">{{ $operation['method'] }}</span>
                                                <span class="truncate text-xs">{{ $operation['summary'] ?? $operation['path'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </aside>

        <div class="flex-1 min-w-0 flex flex-col gap-4">
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="card-title text-lg">{{ $document['info']['title'] }}</h2>
                        <span class="badge badge-ghost font-mono">v{{ $document['info']['version'] }}</span>
                        <a href="{{ url('/api/v2/openapi.json') }}" target="_blank" class="btn btn-ghost btn-sm gap-1.5 ml-auto">
                            <x-icon name="file-json" size="sm" />
                            openapi.json
                        </a>
                    </div>

                    <div class="prose prose-sm max-w-none opacity-80">
                        @foreach (explode("\n\n", $document['info']['description']) as $paragraph)
                            <p>{!! \Illuminate\Support\Str::inlineMarkdown($paragraph, ["html_input" => "escape", "allow_unsafe_links" => false]) !!}</p>
                        @endforeach
                    </div>

                    <div class="text-xs opacity-60">
                        {{ __('This page is written from the registry, not kept beside it: a module that declares a resource is documented by the same act, and cannot drift from what the code serves.') }}
                    </div>
                </div>
            </div>

            {{-- One token for the whole page, remembered in this browser and sent
                 with every call made from here. Kept out of the URL and off the
                 server: it is the reader's own key, not the page's. --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-2 py-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <fieldset class="fieldset p-0 flex-1 min-w-64">
                            <legend class="fieldset-legend">{{ __('Bearer token, for trying calls from this page') }}</legend>
                            <input type="password" x-model="token" @input="remember()" autocomplete="off"
                                   data-api-token
                                   class="input input-bordered input-sm w-full font-mono"
                                   placeholder="tg_…">
                        </fieldset>
                        <a href="{{ route('admin.api.tokens') }}" class="btn btn-ghost btn-sm">{{ __('Issue one') }}</a>
                    </div>
                    <div class="text-xs opacity-60">
                        {{ __('Kept in this browser only. The shop half needs none — it is as public as the storefront’s own pages.') }}
                    </div>
                </div>
            </div>

            @foreach ($groups as $section => $tags)
                @foreach ($tags as $tag => $operations)
                    @php
                        $tagInfo = collect($document['tags'])->firstWhere('name', $section.' · '.$tag);
                    @endphp

                    <div class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wider opacity-40">{{ $section }}</div>
                                <h2 class="card-title text-base">{{ $tag }}</h2>
                                @if ($tagInfo['description'] ?? null)
                                    <p class="text-sm opacity-70 mt-1">{{ $tagInfo['description'] }}</p>
                                @endif
                            </div>

                            <div class="flex flex-col gap-2">
                                @foreach ($operations as $operation)
                                    @php
                                        $id = $anchor($operation['method'], $operation['path']);
                                        $body = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                                        $pathParams = collect($operation['parameters'])->filter(fn ($p) => ($p['in'] ?? null) === 'path');
                                        $queryParams = collect($operation['parameters'])->filter(fn ($p) => ($p['in'] ?? null) === 'query');
                                        $headerParams = collect($operation['parameters'])->map(fn ($p) => isset($p['$ref']) ? ($document['components']['parameters'][basename($p['$ref'])] ?? []) : $p)->filter(fn ($p) => ($p['in'] ?? null) === 'header');
                                    @endphp

                                    <details id="{{ $id }}" class="collapse collapse-arrow border border-base-300 bg-base-200/40 rounded-box">
                                        <summary class="collapse-title min-h-0 py-2.5 pr-10 flex flex-wrap items-center gap-3 cursor-pointer">
                                            <span class="badge {{ $verbClass[$operation['method']] ?? '' }} badge-sm font-mono uppercase w-16 shrink-0">{{ $operation['method'] }}</span>
                                            <code class="font-mono text-sm">{{ $operation['path'] }}</code>
                                            <span class="text-sm opacity-60 truncate">{{ $operation['summary'] ?? '' }}</span>
                                            @if (empty($operation['security']))
                                                <span class="badge badge-ghost badge-xs ml-auto">{{ __('public') }}</span>
                                            @else
                                                <span class="badge badge-outline badge-xs ml-auto gap-1"><x-icon name="lock" size="sm" class="w-3 h-3" />{{ __('token') }}</span>
                                            @endif
                                        </summary>

                                        <div class="collapse-content flex flex-col gap-4 text-sm">
                                            @if ($operation['description'] ?? null)
                                                <div class="prose prose-sm max-w-none opacity-80">
                                                    @foreach (array_filter(explode("\n\n", $operation['description'])) as $paragraph)
                                                        <p>{!! \Illuminate\Support\Str::inlineMarkdown($paragraph, ["html_input" => "escape", "allow_unsafe_links" => false]) !!}</p>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if ($pathParams->isNotEmpty() || $queryParams->isNotEmpty())
                                                <div>
                                                    <div class="font-semibold text-xs uppercase tracking-wider opacity-50 mb-1">{{ __('Parameters') }}</div>
                                                    <div class="overflow-x-auto">
                                                        <table class="table table-xs">
                                                            <tbody>
                                                                @foreach ($pathParams->concat($queryParams) as $parameter)
                                                                    <tr>
                                                                        <td class="font-mono whitespace-nowrap align-top">{{ $parameter['name'] }}</td>
                                                                        <td class="opacity-50 whitespace-nowrap align-top text-xs">
                                                                            {{ $parameter['in'] }}{{ ($parameter['required'] ?? false) ? ', '.__('required') : '' }}
                                                                        </td>
                                                                        <td class="opacity-75">
                                                                            {!! \Illuminate\Support\Str::inlineMarkdown($parameter['description'] ?? '', ["html_input" => "escape", "allow_unsafe_links" => false]) !!}
                                                                            @if ($parameter['schema']['enum'] ?? null)
                                                                                <code class="text-xs">{{ implode(' | ', $parameter['schema']['enum']) }}</code>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($headerParams->isNotEmpty())
                                                <div class="text-xs opacity-60">
                                                    @foreach ($headerParams as $header)
                                                        <div><code class="font-mono">{{ $header['name'] }}</code> — {{ $header['description'] ?? '' }}</div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if ($body)
                                                <div>
                                                    <div class="font-semibold text-xs uppercase tracking-wider opacity-50 mb-1">{{ __('Body') }}</div>
                                                    @include('torgash.api::partials.schema', ['schemaNode' => $body, 'schemaDocument' => $document, 'schemaDepth' => 0])
                                                </div>
                                            @endif

                                            <div>
                                                <div class="font-semibold text-xs uppercase tracking-wider opacity-50 mb-1">{{ __('Answers') }}</div>
                                                <div class="flex flex-col gap-2">
                                                    @foreach ($operation['responses'] as $code => $response)
                                                        @php $answerSchema = $resolve($response['content']['application/json']['schema'] ?? []); @endphp
                                                        <div>
                                                            <div class="flex items-baseline gap-2">
                                                                <span @class([
                                                                    'badge badge-sm font-mono',
                                                                    'badge-success' => $code < 300,
                                                                    'badge-error' => $code >= 300,
                                                                ])>{{ $code }}</span>
                                                                <span class="opacity-75">{{ $response['description'] }}</span>
                                                            </div>
                                                            @if ($code < 300 && $answerSchema)
                                                                <div class="mt-1">
                                                                    @include('torgash.api::partials.schema', ['schemaNode' => $answerSchema, 'schemaDocument' => $document, 'schemaDepth' => 0])
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>

                                            {{-- Try it: the same call, from here. A reference you cannot
                                                 press is a reference you have to believe. --}}
                                            <div class="border-t border-base-300 pt-3"
                                                 x-data="call('{{ strtoupper($operation['method']) }}', @js($operation['path']), @js($body ? json_encode($openApi->sample($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null))">
                                                <div class="flex flex-wrap items-end gap-2">
                                                    @foreach ($pathParams as $parameter)
                                                        <fieldset class="fieldset p-0">
                                                            <legend class="fieldset-legend text-xs font-mono">{{ $parameter['name'] }}</legend>
                                                            <input x-model="path['{{ $parameter['name'] }}']" class="input input-bordered input-xs w-44 font-mono">
                                                        </fieldset>
                                                    @endforeach
                                                    @foreach ($queryParams->take(6) as $parameter)
                                                        <fieldset class="fieldset p-0">
                                                            <legend class="fieldset-legend text-xs font-mono">{{ $parameter['name'] }}</legend>
                                                            <input x-model="query['{{ $parameter['name'] }}']" class="input input-bordered input-xs w-32 font-mono"
                                                                   placeholder="{{ $parameter['example'] ?? '' }}">
                                                        </fieldset>
                                                    @endforeach
                                                    <button class="btn btn-primary btn-sm gap-1" @click="send()" :disabled="busy">
                                                        <x-icon name="play" size="sm" class="w-3 h-3" />
                                                        {{ __('Send') }}
                                                    </button>
                                                </div>

                                                @if ($body)
                                                    <textarea x-model="payload" rows="5" spellcheck="false"
                                                              class="textarea textarea-bordered w-full font-mono text-xs mt-2"></textarea>
                                                @endif

                                                <template x-if="response !== null">
                                                    <div class="mt-2">
                                                        <div class="flex items-center gap-2 text-xs">
                                                            <span class="badge badge-sm font-mono" :class="status < 300 ? 'badge-success' : 'badge-error'" x-text="status"></span>
                                                            <code class="opacity-60 break-all" x-text="sent"></code>
                                                        </div>
                                                        <pre class="mockup-code text-xs mt-1 max-h-80 overflow-auto"><code x-text="response"></code></pre>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        function apiReference() {
            return {
                token: '',
                init() {
                    try { this.token = localStorage.getItem('torgash-api-token') || ''; } catch (e) {}
                },
                remember() {
                    try { localStorage.setItem('torgash-api-token', this.token); } catch (e) {}
                },
            };
        }

        // A link from the map opens what it points at. Scrolling to a closed row
        // and leaving it closed is the one thing that makes a sidebar useless.
        (function () {
            const open = () => {
                const target = location.hash && document.querySelector(location.hash);

                if (target && target.tagName === 'DETAILS') {
                    target.open = true;
                    target.scrollIntoView({block: 'start'});
                }
            };

            window.addEventListener('hashchange', open);
            document.addEventListener('DOMContentLoaded', open);
        })();

        function call(method, template, payload) {
            return {
                method, template, payload,
                path: {}, query: {},
                busy: false, status: null, response: null, sent: '',

                url() {
                    let path = this.template.replace(/\{(\w+)\}/g, (m, name) => this.path[name] || m);
                    const query = Object.entries(this.query)
                        .filter(([, value]) => value !== '' && value !== undefined)
                        .map(([name, value]) => encodeURIComponent(name) + '=' + encodeURIComponent(value));

                    return path + (query.length ? '?' + query.join('&') : '');
                },

                async send() {
                    this.busy = true;
                    this.sent = this.method + ' ' + this.url();

                    try {
                        const headers = {'Accept': 'application/json'};
                        // One token box for the whole page, read at the moment
                        // of sending rather than copied into every panel.
                        const key = (document.querySelector('[data-api-token]') || {}).value;

                        if (key) headers['Authorization'] = 'Bearer ' + key;
                        if (this.payload) headers['Content-Type'] = 'application/json';

                        const answer = await fetch(this.url(), {
                            method: this.method,
                            headers,
                            body: this.payload && this.method !== 'GET' ? this.payload : undefined,
                        });

                        this.status = answer.status;

                        const text = await answer.text();

                        try {
                            this.response = JSON.stringify(JSON.parse(text), null, 2);
                        } catch (e) {
                            this.response = text.slice(0, 4000);
                        }
                    } catch (e) {
                        this.status = 0;
                        this.response = String(e);
                    } finally {
                        this.busy = false;
                    }
                },
            };
        }
    </script>
@endpush
