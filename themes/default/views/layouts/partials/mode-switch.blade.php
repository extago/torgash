{{-- Light / dark (theme setting "show_mode_switch"). Drawn in more than one
     place — the header's strip on a desktop, the phone menu's top row — so
     no id: every switch on the page is found by its class and kept in step
     with the others, and the choice is remembered in the browser
     (storefront.blade.php reads it back before the page paints).
     $modeSwitchClass: the button's look where it stands. --}}
@if (theme_setting('show_mode_switch', true))
    <label class="swap swap-rotate btn btn-ghost btn-sm btn-circle {{ $modeSwitchClass ?? '' }}" aria-label="{{ __('Dark theme') }}">
        <input type="checkbox" class="theme-controller torgash-mode-switch" value="torgash-dark">
        <x-icon name="sun" class="swap-off" />
        <x-icon name="moon" class="swap-on" />
    </label>
    @once
        <script nonce="{{ csp_nonce() }}">
            (function () {
                var all = function () { return document.querySelectorAll('.torgash-mode-switch'); };
                var current = document.documentElement.getAttribute('data-theme')
                    || (matchMedia('(prefers-color-scheme: dark)').matches ? 'torgash-dark' : 'torgash-light');
                var show = function (theme) { all().forEach(function (box) { box.checked = theme === 'torgash-dark'; }); };
                document.addEventListener('DOMContentLoaded', function () { show(current); });
                show(current);
                document.addEventListener('change', function (event) {
                    if (!event.target.classList || !event.target.classList.contains('torgash-mode-switch')) return;
                    var theme = event.target.checked ? 'torgash-dark' : 'torgash-light';
                    document.documentElement.setAttribute('data-theme', theme);
                    show(theme);
                    try { localStorage.setItem('torgash-theme', theme); } catch (e) {}
                });
            })();
        </script>
    @endonce
@endif
