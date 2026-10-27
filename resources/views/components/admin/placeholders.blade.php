@props([
    // Which groups to offer. Null is everything; a list narrows it — the icon
    // tokens alone are two thousand rows, which is a menu nobody reads.
    'groups' => null,
    // What the tokens may be typed into. Focus decides which one actually gets
    // them, so this only has to be wide enough to cover the form's own fields.
    // `input:not([type])` is in there because our own text fields are written
    // without one — an attribute selector matches what is typed, not what the
    // browser defaults to, and leaving it out made the picker insert nothing.
    'fields' => 'textarea, input[type="text"], input:not([type])',
    // A stable id, so a page may carry two of these without their scripts
    // reaching into each other's dialog.
    'name' => 'placeholders',
    'trigger' => null,
    'triggerClass' => 'btn btn-ghost btn-sm',
    'iconOnly' => false,
])
@php
    $registry = app(\App\Core\Content\Placeholders::class);
    $offered = collect($registry->grouped())
        ->when($groups, fn ($all) => $all->only($groups));
    $dialogId = 'placeholders-'.$name;
    $pickerLocale = app()->getLocale();

    // Groups are registry keys, not sentences — name the ones we ship so the
    // box reads as an admin screen rather than as a data dump. A module's own
    // group ("forms") is read as an English word its lang file translates.
    $groupLabels = [
        'page' => __('This page'),
        'product' => __('The product beside it'),
        'shop' => __('The shop'),
        'contacts' => __('Contacts'),
        'order' => __('The order'),
        'icons' => __('Icons'),
        'general' => __('General'),
    ];
@endphp

{{--
    The list of what an admin may type in braces, and a click that types it.

    One component rather than a box per screen: the registry is global, the
    syntax (modifiers, arguments, optional fragments) is global too, and three
    hand-written copies of it are three chances for one to go stale the next
    time a module registers a token.

    Insertion goes to the field that was last FOCUSED, because nothing here can
    know which of a form's fields the author means — and a template screen has
    one field per meta line, per language.
--}}

@if ($offered->flatten(1)->isNotEmpty())
    <button type="button" class="{{ $triggerClass }}{{ $iconOnly ? ' btn-square' : '' }}"
            title="{{ __('Available placeholders') }}"
            data-open-dialog="{{ $dialogId }}">
        <x-icon name="braces" size="sm" />
        @unless ($iconOnly)
            {{ $trigger ?? __('Placeholders') }}
        @endunless
    </button>

    <dialog id="{{ $dialogId }}" class="modal">
        <div class="modal-box max-w-2xl">
            <h3 class="font-semibold text-lg">{{ __('Available placeholders') }}</h3>
            <p class="text-sm opacity-60 mt-1">
                {{ __('Click one to insert it where you were last typing. Values are escaped automatically unless the placeholder produces markup.') }}
            </p>

            <div class="flex flex-col gap-4 mt-4">
                @foreach ($offered as $group => $tokens)
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-2">
                            {{ $groupLabels[$group] ?? __(\Illuminate\Support\Str::ucfirst($group)) }}
                        </div>
                        <div class="flex flex-col gap-1">
                            @foreach ($tokens as $token)
                                @php
                                    // A token that takes an argument is offered
                                    // WITH one: bare, it resolves to nothing,
                                    // and an example is the shortest way to say
                                    // that it wants a code.
                                    $written = '{'.$token['token']
                                        .($token['argument'] ? ':'.($token['argument']['example'] ?? '…') : '')
                                        .'}';
                                @endphp
                                @php
                                    // An argument with a known list of values (the cases
                                    // of each language) is offered value by value, in
                                    // words, under the token.
                                    $choices = $token['argument']['choices'] ?? null;
                                    $choices = is_callable($choices) ? (array) $choices() : (array) $choices;
                                @endphp
                                <button type="button"
                                        class="flex items-baseline gap-2 text-left p-2 rounded-box hover:bg-base-200"
                                        data-insert="{{ $written }}">
                                    <code class="font-mono text-xs bg-base-200 px-1.5 py-0.5 rounded shrink-0">{{ $written }}</code>
                                    <span class="text-sm opacity-70 min-w-0">
                                        {{ label_text($token['label'] ?? null, $pickerLocale) }}
                                        @if ($token['raw'])
                                            <span class="badge badge-ghost badge-xs align-middle">HTML</span>
                                        @endif
                                        @if (! empty($token['example']) && $token['example'] !== $written)
                                            <span class="opacity-50">— {{ $token['example'] }}</span>
                                        @endif
                                    </span>
                                </button>
                                @if ($choices)
                                    <div class="flex flex-wrap gap-1 pl-2 pb-1">
                                        @foreach ($choices as $choice)
                                            @php $choiceWritten = '{'.$token['token'].':'.$choice['value'].'}'; @endphp
                                            <button type="button" class="btn btn-ghost btn-sm font-normal" data-insert="{{ $choiceWritten }}"
                                                    title="{{ $choiceWritten }}">{{ $choice['label'] }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-box border border-base-300 p-3 text-xs mt-4 leading-relaxed opacity-70">
                {{ __('Add |lower, |upper or |ucfirst to change the case: {category|lower}.') }}<br>
                {{ __('Square brackets holding a token disappear whole when it is empty, so "{product}[ — {feature:color}]" prints the dash only when there is a colour.') }}
            </div>

            <div class="modal-action">
                <button type="button" class="btn btn-sm" data-close-dialog>{{ __('Close') }}</button>
            </div>
        </div>
        {{-- The backdrop as a button, never daisyUI's own form: this dialog is
             rendered beside an admin form, and a nested form is dropped by the
             parser while its inputs stay behind. --}}
        <button type="button" class="modal-backdrop" tabindex="-1" aria-hidden="true"
                data-close-dialog></button>
    </dialog>

    <script nonce="{{ csp_nonce() }}">
        (function () {
            var dialog = document.getElementById(@json($dialogId));
            var selector = @json($fields);
            var lastField = null;

            document.addEventListener('focusin', function (e) {
                if (e.target.matches && e.target.matches(selector)) {
                    lastField = e.target;
                }
            });

            dialog.querySelectorAll('[data-insert]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var field = lastField || document.querySelector(selector);
                    if (!field) { return; }
                    var token = button.dataset.insert;
                    var start = field.selectionStart ?? field.value.length;
                    var end = field.selectionEnd ?? field.value.length;
                    field.value = field.value.slice(0, start) + token + field.value.slice(end);
                    // Caret after what was just inserted, so several tokens in a
                    // row land where the author expects.
                    field.selectionStart = field.selectionEnd = start + token.length;
                    dialog.close();
                    field.focus();
                });
            });
        })();
    </script>
@endif
