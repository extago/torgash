@extends('admin.layout')

@section('title', label_text($manifest->name).' — '.__('Documentation'))

@section('content')
    <style>
        /* No Tailwind Typography plugin in this build, so the rendered Markdown
           gets its spacing here. Scoped to .module-doc — it is the only raw
           HTML on the page. */
        .module-doc { line-height: 1.65; }
        .module-doc > :first-child { margin-top: 0; }
        .module-doc h1, .module-doc h2, .module-doc h3, .module-doc h4 { font-weight: 600; line-height: 1.3; margin: 1.6em 0 .6em; }
        .module-doc h1 { font-size: 1.6rem; }
        .module-doc h2 { font-size: 1.3rem; }
        .module-doc h3 { font-size: 1.1rem; }
        .module-doc p, .module-doc ul, .module-doc ol, .module-doc blockquote, .module-doc pre, .module-doc table { margin: .85em 0; }
        .module-doc ul { list-style: disc; padding-left: 1.4em; }
        .module-doc ol { list-style: decimal; padding-left: 1.4em; }
        .module-doc li { margin: .3em 0; }
        .module-doc a { color: var(--color-primary, #3b82f6); text-decoration: underline; }
        .module-doc code { font-family: ui-monospace, monospace; font-size: .9em; background: color-mix(in oklab, currentColor 10%, transparent); padding: .1em .35em; border-radius: .25rem; }
        .module-doc pre { background: color-mix(in oklab, currentColor 8%, transparent); padding: .9em 1em; border-radius: .5rem; overflow-x: auto; }
        .module-doc pre code { background: none; padding: 0; font-size: .85em; }
        .module-doc blockquote { border-left: 3px solid color-mix(in oklab, currentColor 25%, transparent); padding-left: 1em; opacity: .85; }
        .module-doc img { max-width: 100%; height: auto; border-radius: .5rem; margin: 1em 0; }
        .module-doc table { border-collapse: collapse; display: block; overflow-x: auto; }
        .module-doc th, .module-doc td { border: 1px solid color-mix(in oklab, currentColor 20%, transparent); padding: .4em .7em; text-align: left; }
        .module-doc hr { border: 0; border-top: 1px solid color-mix(in oklab, currentColor 20%, transparent); margin: 1.8em 0; }
    </style>

    <div class="flex items-center gap-3 mb-4">
        @can('modules.manage')
        <a href="{{ route('admin.modules') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Modules') }}" aria-label="{{ __('Modules') }}">
            <x-icon name="arrow-left" />
        </a>
        @endcan
        <div>
            <h1 class="text-xl font-semibold leading-tight">{{ label_text($manifest->name) }}</h1>
            <div class="text-xs font-mono opacity-50">{{ $manifest->id }} · {{ __('Documentation') }}</div>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body p-0 lg:flex-row lg:gap-0">
            {{-- Side tabs: one per page the module ships. A single page still
                 renders the rail so the layout does not jump between modules. --}}
            <nav class="lg:w-56 shrink-0 border-b lg:border-b-0 lg:border-r border-base-300 p-2 flex lg:flex-col gap-1 overflow-x-auto">
                @foreach ($pages as $page)
                    <a href="{{ route('admin.modules.docs', ['id' => $manifest->id, 'doc' => $page['slug']]) }}"
                       @class([
                           'px-3 py-2 rounded-lg text-sm whitespace-nowrap lg:whitespace-normal',
                           'bg-primary text-primary-content' => $page['slug'] === $active,
                           'hover:bg-base-200' => $page['slug'] !== $active,
                       ])>
                        {{ $page['title'] }}
                    </a>
                @endforeach
            </nav>

            <div class="module-doc min-w-0 flex-1 p-6 lg:p-8">
                {!! $html !!}
            </div>
        </div>
    </div>
@endsection
