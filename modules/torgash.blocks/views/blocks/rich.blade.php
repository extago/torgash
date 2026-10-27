{{--
    The safe default text block: everything is escaped, so it needs no special
    permission. `blocks.html` exists for the case this deliberately cannot do.
--}}
@php
    $heading = trim((string) ($settings['heading'] ?? ''));
    $body = trim((string) ($settings['body'] ?? ''));
    $align = ($settings['align'] ?? 'start') === 'center' ? 'text-center' : 'text-start';
@endphp
@if ($heading !== '' || $body !== '')
    <div class="torgash-block torgash-block-rich {{ $align }} my-6">
        @if ($heading !== '')
            <h2 class="text-lg font-semibold mb-2">{{ $heading }}</h2>
        @endif
        @if ($body !== '')
            <div class="opacity-80 whitespace-pre-line">{{ $body }}</div>
        @endif
    </div>
@endif
