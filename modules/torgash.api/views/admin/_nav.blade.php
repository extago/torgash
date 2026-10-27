{{-- The API: who may call it and what it answers — one subject, two screens. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.api.tokens') }}" @class(['tab gap-2', 'tab-active' => $apiScreen === 'tokens'])>
        <x-icon name="key-round" size="sm" /> {{ __('API tokens') }}
    </a>
    <a role="tab" href="{{ route('admin.api.docs') }}" @class(['tab gap-2', 'tab-active' => $apiScreen === 'docs'])>
        <x-icon name="book-open" size="sm" /> {{ __('API reference') }}
    </a>
</div>
