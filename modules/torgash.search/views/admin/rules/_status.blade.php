{{-- Whether a rule is in force, switched in place. --}}
<td id="rule-status-{{ $kind }}-{{ $rulesRow->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $rulesRow->enabled, 'badge-ghost' => ! $rulesRow->enabled])
             title="{{ __('Change status') }}">
            {{ $rulesRow->enabled ? __('Rule works') : __('Rule is off') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-36 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Rule works'), 0 => __('Rule is off')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.search-engine.rules.status', [$kind, $rulesRow->id]) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#rule-status-{{ $kind }}-{{ $rulesRow->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $rulesRow->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
