@extends('admin.layout')

@section('title', isset($only) ? label_text($only->name).' · '.__('Settings') : __('Settings'))
@if (isset($only))
    @section('own_heading', '1')
@endif

{{--
    Every setting of the shop on one page — which the owner likes, and which
    at thirty modules needs a way around it. So: the modules filed under the
    sidebar's own sections (the controller does that), a table of contents
    that stays in view and follows the reading, and a box that hides every
    setting not matching what is typed — read from the page's own translated
    text, so it finds "Вес" and "Weight" alike without an index anywhere.
    One module alone (`$only`, the gear on the Modules screen) draws no
    table of contents: there is nothing to find.
--}}

@section('content')
    @if (! isset($only) && session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! isset($only) && $errors->any())
        <div class="alert alert-error text-sm flex-col items-start gap-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if (isset($only))
        @include('admin.settings._module', ['settingsSection' => $sections[0]])
    @elseif (empty($sections))
        <div class="alert">{{ __('No installed module declares any settings.') }}</div>
    @else
        <div class="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)] items-start" data-settings-screen>
            <aside class="lg:sticky lg:top-20 flex flex-col gap-3 lg:max-h-[calc(100vh-6rem)]">
                <label class="input input-sm w-full gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" class="grow" data-settings-filter autocomplete="off" placeholder="{{ __('Find a setting…') }}" aria-label="{{ __('Find a setting') }}">
                </label>

                <nav class="relative hidden lg:flex flex-col gap-3 overflow-y-auto pr-1 text-sm" data-settings-toc aria-label="{{ __('Settings') }}">
                    @foreach ($groups as $groupKey => $group)
                        <div data-toc-group>
                            <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider opacity-50 px-2 mb-1">
                                <x-icon :name="$group['icon']" size="sm" /> {{ $group['label'] }}
                            </div>
                            @foreach ($group['sections'] as $tocSection)
                                <a href="#module-{{ $tocSection['module']->id }}" data-toc-link="{{ $tocSection['module']->id }}"
                                   class="block rounded-field px-2 py-1 hover:bg-base-200 truncate">{{ label_text($tocSection['module']->name) }}</a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
            </aside>

            <div class="flex flex-col gap-4 min-w-0">
                <p class="text-sm opacity-60 hidden" data-settings-empty>{{ __('No setting matches this search.') }}</p>

                @foreach ($groups as $groupKey => $group)
                    <div class="flex flex-col gap-4" data-settings-group>
                        <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider opacity-60 pt-2 first:pt-0">
                            <x-icon :name="$group['icon']" size="sm" /> {{ $group['label'] }}
                        </h2>
                        @foreach ($group['sections'] as $groupSection)
                            <section id="module-{{ $groupSection['module']->id }}" class="flex flex-col gap-4 scroll-mt-20" data-settings-module="{{ $groupSection['module']->id }}">
                                @include('admin.settings._section', ['settingsSection' => $groupSection])
                            </section>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <script nonce="{{ csp_nonce() }}">
            (function () {
                var screen = document.querySelector('[data-settings-screen]');
                if (!screen) return;

                var input = screen.querySelector('[data-settings-filter]');
                var modules = Array.prototype.slice.call(screen.querySelectorAll('[data-settings-module]'));
                var links = {};
                screen.querySelectorAll('[data-toc-link]').forEach(function (a) { links[a.dataset.tocLink] = a; });

                // What each setting and each card SAYS, read once from the page
                // — already in the reader's language.
                var norm = function (text) { return (text || '').toLowerCase().replace(/\s+/g, ' '); };
                screen.querySelectorAll('.torgash-setting').forEach(function (el) { el.dataset.text = norm(el.textContent); });
                screen.querySelectorAll('[data-settings-card]').forEach(function (card) {
                    var title = card.querySelector('.card-title');
                    card.dataset.title = norm(title ? title.textContent : '');
                });

                function filter() {
                    var q = norm(input.value).trim();
                    var any = false;

                    modules.forEach(function (module) {
                        var moduleName = norm((links[module.dataset.settingsModule] || {}).textContent || '') + ' ' + module.dataset.settingsModule;
                        var moduleHit = q !== '' && moduleName.indexOf(q) !== -1;
                        var moduleShown = false;

                        module.querySelectorAll('[data-settings-card]').forEach(function (card) {
                            // A card whose own heading (or whose module) matches
                            // is shown whole; otherwise only its matching settings.
                            var cardHit = q === '' || moduleHit || card.dataset.title.indexOf(q) !== -1;
                            var cardShown = false;

                            card.querySelectorAll('.torgash-setting').forEach(function (setting) {
                                var show = cardHit || setting.dataset.text.indexOf(q) !== -1;
                                setting.hidden = !show;
                                cardShown = cardShown || show;
                            });

                            card.hidden = !cardShown;
                            moduleShown = moduleShown || cardShown;
                        });

                        module.hidden = !moduleShown;
                        if (links[module.dataset.settingsModule]) links[module.dataset.settingsModule].hidden = !moduleShown;
                        any = any || moduleShown;
                    });

                    screen.querySelectorAll('[data-settings-group]').forEach(function (group) {
                        group.hidden = !group.querySelector('[data-settings-module]:not([hidden])');
                    });
                    screen.querySelectorAll('[data-toc-group]').forEach(function (group) {
                        group.hidden = !group.querySelector('[data-toc-link]:not([hidden])');
                    });
                    screen.querySelector('[data-settings-empty]').classList.toggle('hidden', any);
                }

                input.addEventListener('input', function () {
                    filter();
                    // What is left is read from its top: typing halfway down
                    // the page would otherwise leave the answers above the fold.
                    window.scrollTo({ top: 0 });
                });

                // The table of contents follows the reading: the module whose
                // top has most recently passed under the header is the one lit.
                var current = null;
                function spy() {
                    var top = 120, found = null;
                    modules.forEach(function (module) {
                        if (!module.hidden && module.getBoundingClientRect().top <= top) found = module;
                    });
                    found = found || modules.find(function (m) { return !m.hidden; });
                    var id = found ? found.dataset.settingsModule : null;
                    if (id === current) return;
                    if (current && links[current]) links[current].classList.remove('bg-base-200', 'font-medium', 'text-primary');
                    current = id;
                    if (id && links[id]) {
                        var link = links[id], toc = link.closest('[data-settings-toc]');
                        link.classList.add('bg-base-200', 'font-medium', 'text-primary');
                        // Keep the lit entry in view inside the contents' own
                        // scroll — never the page's, which the reader is moving.
                        if (link.offsetTop < toc.scrollTop) toc.scrollTop = link.offsetTop - 8;
                        else if (link.offsetTop + link.offsetHeight > toc.scrollTop + toc.clientHeight) toc.scrollTop = link.offsetTop + link.offsetHeight - toc.clientHeight + 8;
                    }
                }
                document.addEventListener('scroll', spy, { passive: true });
                // A browser that kept the box's text across a reload keeps
                // the page it described, not a full page under a filled box.
                if (input.value) filter();
                spy();
            })();
        </script>
    @endif
@endsection
