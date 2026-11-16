{{-- Bonus points: what happened and what the shop decided — one subject, two screens. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.points.index') }}" @class(['tab gap-2', 'tab-active' => $pointsScreen === 'movements'])>
        <x-icon name="list" size="sm" /> {{ __('Movements') }}
    </a>
    <a role="tab" href="{{ route('admin.points.rules.index') }}" @class(['tab gap-2', 'tab-active' => $pointsScreen === 'rules'])>
        <x-icon name="list-ordered" size="sm" /> {{ __('Rules') }}
    </a>
</div>
