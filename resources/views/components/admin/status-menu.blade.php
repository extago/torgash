{{--
    A row's status in an admin list, switched in place: a solid badge with a
    chevron, opening the choices — the product list's control, for every list
    (a badge that flipped on a click gave no hint it was a control at all,
    and no way to see the other choice before taking it).

        x-admin.status-menu
            :label  what the badge says now (may be richer than the value: "Scheduled")
            :badge  its solid colour class (badge-success / badge-warning / badge-ghost)
            :options value => label, :current the value now
            :url    where a choice goes, as `field` = value
            :target the cell HTMX swaps with the answer (hx-target); without
                    one each choice is a small POST form and the page reloads —
                    only in a list that no form wraps (CLAUDE.md, nested forms).
--}}
@props([
    'label',
    'badge' => 'badge-ghost',
    'options' => [],
    'current' => null,
    'url',
    'field' => 'status',
    'target' => null,
    'method' => 'patch',
])
<div class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="badge {{ $badge }} badge-sm gap-1 cursor-pointer whitespace-nowrap" title="{{ __('Change status') }}">
        {{ $label }}
        <x-icon name="chevron-down" size="sm" />
    </div>
    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
        @foreach ($options as $statusMenuValue => $statusMenuLabel)
            @php $statusMenuOn = (string) $current === (string) $statusMenuValue; @endphp
            <li>
                @if ($target)
                    <button type="button"
                            hx-{{ strtolower($method) }}="{{ $url }}"
                            hx-vals='@json([$field => $statusMenuValue])'
                            hx-target="{{ $target }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => $statusMenuOn])>
                        {{ $statusMenuLabel }}
                    </button>
                @else
                    <form method="POST" action="{{ $url }}" class="contents">
                        @csrf
                        @if (strtoupper($method) !== 'POST')
                            @method(strtoupper($method))
                        @endif
                        <input type="hidden" name="{{ $field }}" value="{{ $statusMenuValue }}">
                        <button @class(['w-full text-left', 'font-semibold text-primary' => $statusMenuOn])>{{ $statusMenuLabel }}</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
</div>
