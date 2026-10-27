{{-- The top of every search screen: its one heading, the way to the module's
     settings (what the window shows, typos, redirects — nothing else on these
     screens links there), and the strip of screens. $searchTab names the one
     being shown. --}}
<div class="flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-bold flex-1">{{ __('Search') }}</h1>
    @can('settings.manage')
        <a href="{{ route('admin.settings.edit', 'torgash.search') }}" class="btn btn-sm btn-ghost gap-2"><x-icon name="settings" /> {{ __('Settings') }}</a>
    @endcan
</div>

<div role="tablist" class="tabs tabs-box w-fit">
    <a role="tab" href="{{ route('admin.search-engine.index') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'engine'])><x-icon name="flask-conical" size="sm" /> {{ __('Index and trial') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'synonyms') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'synonyms'])><x-icon name="arrow-left-right" size="sm" /> {{ __('Synonyms') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'exclusions') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'exclusions'])><x-icon name="ban" size="sm" /> {{ __('Exclusions') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'phrases') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'phrases'])><x-icon name="pin" size="sm" /> {{ __('Phrases') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.stats') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'stats'])><x-icon name="chart-column" size="sm" /> {{ __('Statistics') }}</a>
</div>
