{{-- The top of every search screen under the layout's one heading: the way
     to the module's settings (what the window shows, typos, redirects —
     nothing else on these screens links there) level with it, and the strip
     of screens as the quiet underlined tabs. $searchTab names the one being
     shown. --}}
@can('settings.manage')
    @section('title_actions')
        <a href="{{ route('admin.settings.edit', 'torgash.search') }}" class="btn btn-ghost gap-1.5"><x-icon name="settings" size="sm" /> {{ __('Settings') }}</a>
    @endsection
@endcan

<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.search-engine.index') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'engine'])><x-icon name="flask-conical" size="sm" /> {{ __('Index and trial') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'synonyms') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'synonyms'])><x-icon name="arrow-left-right" size="sm" /> {{ __('Synonyms') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'exclusions') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'exclusions'])><x-icon name="ban" size="sm" /> {{ __('Exclusions') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.rules.index', 'phrases') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'phrases'])><x-icon name="pin" size="sm" /> {{ __('Phrases') }}</a>
    <a role="tab" href="{{ route('admin.search-engine.stats') }}" @class(['tab gap-1.5', 'tab-active' => $searchTab === 'stats'])><x-icon name="chart-column" size="sm" /> {{ __('Statistics') }}</a>
</div>
