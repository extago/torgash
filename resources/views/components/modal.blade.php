@props([
    // Stable dom id, so anything else on the page can open this one:
    //   document.getElementById('modal-delivery').showModal()
    // Omitted, one is generated — enough for a modal opened by its own trigger.
    'name' => null,
    'title' => null,
    // A lucide icon name for the header, e.g. "truck" — the same names the icon
    // component takes. NB: no component tag may appear even in a comment here,
    // see the note below.
    'icon' => null,
    // Content in a box of its own (a quote, a table, a warning) or bare prose.
    'framed' => false,
    'size' => 'md',
    // Whether the header carries an × and the backdrop closes on click. ESC
    // always closes: that is the browser's, and taking it away from a content
    // popup would be rude.
    'dismissible' => true,
    // The button that opens it. A PROP and not a slot, deliberately — see the
    // note below. Empty renders no button at all, for a modal something else
    // opens by id.
    'trigger' => null,
    'triggerClass' => 'link link-primary link-hover',
    // A lucide icon drawn before the trigger's words (a row of small actions
    // under a buy button, where every other control has one).
    'triggerIcon' => null,
])

{{--
    A popup, in three zones: trigger, content, footer — each optional.

        x-modal  title="How to get this price?"  icon="gem"
                 trigger="How to get this price?"
            default slot  what it opens
            slot:footer   the buttons under it, if any

    The trigger is an attribute rather than a slot because a named slot written
    BEFORE the default content leaves an output buffer open — measured, and it
    is Blade's own behaviour, not this component's (any named slot does it, in
    that position). A trigger naturally wants to be written first, so the shape
    that invites the bug is the shape people would reach for. Anything more than
    text in the button: render your own and call
    document.getElementById(name).showModal() — which is what `name` is for.
    The footer slot is safe: it belongs after the content anyway.

    (Written without tag syntax on purpose: Blade's COMPONENT compiler reads the
    raw template text, comments included, so a literal component tag in here is
    compiled — the same trap as a literal @-directive in a string, which
    CLAUDE.md already warns about. It cost half an hour to find once.)

    Built on the native dialog element: focus trapping, ESC, the backdrop and the top
    layer are the browser's job, and this is one component rather than a
    dependency. daisyUI dresses it.

    NO <form method="dialog"> anywhere, which is the idiom daisyUI's own examples
    use — a modal may well be rendered inside another form (the buy box is one),
    and a nested form is dropped by the parser while its inputs stay behind,
    which is the bug that once deleted products. Every button here is
    type="button" and closes by calling close() instead.
--}}

@php
    $id = 'modal-'.($name ?: \Illuminate\Support\Str::random(8));

    $width = match ($size) {
        'sm' => 'max-w-md',
        'lg' => 'max-w-3xl',
        default => 'max-w-xl',
    };

    $hasHeader = filled($title) || filled($icon) || $dismissible;
@endphp

@if (filled($trigger))
    <button type="button" class="{{ $triggerClass }}"
            data-open-dialog="{{ $id }}">
        @if (filled($triggerIcon))<x-icon :name="$triggerIcon" size="sm" />@endif
        {{ $trigger }}
    </button>
@endif

<dialog id="{{ $id }}" class="modal">
    {{-- No taller than the screen: the header and the footer stay, the content
         between them scrolls (a long list of block types did not). --}}
    <div class="modal-box p-0 overflow-hidden flex flex-col max-h-[calc(100dvh-2rem)] {{ $width }}">
        @if ($hasHeader)
            <div class="flex items-center gap-2.5 px-5 py-3 border-b border-base-300 shrink-0">
                @if (filled($icon))
                    <x-icon :name="$icon" size="lg" class="text-primary shrink-0" />
                @endif
                @if (filled($title))
                    <h3 class="font-semibold leading-tight">{{ $title }}</h3>
                @endif
                @if ($dismissible)
                    <button type="button" class="btn btn-ghost btn-sm btn-circle ms-auto shrink-0"
                            aria-label="{{ __('Close') }}"
                            data-close-dialog>
                        <x-icon name="x" />
                    </button>
                @endif
            </div>
        @endif

        <div class="px-5 py-4 overflow-y-auto min-h-0">
            @if ($framed)
                <div class="rounded-box border border-base-300 bg-base-200/40 p-4">{{ $slot }}</div>
            @else
                {{ $slot }}
            @endif
        </div>

        @isset($footer)
            <div class="px-5 py-3 border-t border-base-300 bg-base-200/40 flex flex-wrap justify-end gap-2 shrink-0">
                {{ $footer }}
            </div>
        @endisset
    </div>

    @if ($dismissible)
        {{-- The backdrop as a button rather than daisyUI's form: same click
             target, no nested form. tabindex -1 keeps it out of the tab order,
             where it would otherwise be an unlabelled stop before the content. --}}
        <button type="button" class="modal-backdrop" tabindex="-1" aria-hidden="true"
                data-close-dialog></button>
    @endif
</dialog>
