{{--
    A column per top entry: its name as the column's title (a link when it is
    one), its children beneath, their children smaller under each — the shape
    of a footer, and of a page listing departments. Lays out by its OWN width,
    since a shop may place it anywhere: as many columns of at least 12rem as
    fit, at most four (a track is never narrower than a quarter), and never
    more than it has — a menu of one column fixed to two left an empty half
    beside it (two menus one above the other in a footer).
--}}
@php $menuLabelWraps = true; @endphp
<nav class="torgash-menu torgash-menu-columns @container" @if ($menuHeading !== '') aria-label="{{ $menuHeading }}" @endif>
    @if ($menuHeading !== '')
        <div class="font-semibold mb-3">{{ $menuHeading }}</div>
    @endif
    <div class="grid gap-6 grid-cols-[repeat(auto-fit,minmax(max(min(100%,12rem),calc((100%_-_4.5rem)/4)),1fr))]">
        @foreach ($menuNodes as $menuColumn)
            @continue($menuColumn->isDivider())
            @php $menuColumnFolds = $menuColumn->hasChildren() && $menuDepth > 1; @endphp
            {{-- A column with links under its title can FOLD: the container
                 decides (the footer's "fold menus on a phone", app.css), the
                 toggle is drawn here and stays hidden everywhere else. --}}
            <div class="{{ $menuColumn->screenClass() }} {{ $menuColumn->cssClass }}"
                 @if ($menuColumnFolds) data-fold x-data="{ open: false }" x-bind:data-open="open" @endif>
                <div class="font-semibold mb-2 torgash-fold-title flex items-center justify-between gap-2">
                    <span class="min-w-0">@include('torgash.menus::looks.partials.column-link', ['menuColumnLink' => $menuColumn])</span>
                    @if ($menuColumnFolds)
                        <button type="button" class="torgash-fold-toggle btn btn-ghost btn-sm btn-square" x-on:click="open = ! open"
                                x-bind:aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-label="{{ __('Show the links') }}"><x-icon name="chevron-down" /></button>
                    @endif
                </div>
                @if ($menuColumnFolds)
                    <ul class="torgash-fold-list flex flex-col gap-1.5 text-sm">
                        @foreach ($menuColumn->children as $menuColumnChild)
                            @continue($menuColumnChild->isDivider())
                            <li class="{{ $menuColumnChild->screenClass() }}">
                                @include('torgash.menus::looks.partials.column-link', ['menuColumnLink' => $menuColumnChild])
                                @if ($menuColumnChild->hasChildren() && $menuDepth > 2)
                                    <ul class="mt-1 ml-3 flex flex-col gap-1 text-xs opacity-80">
                                        @foreach ($menuColumnChild->children as $menuColumnGrandchild)
                                            @continue($menuColumnGrandchild->isDivider())
                                            <li>@include('torgash.menus::looks.partials.column-link', ['menuColumnLink' => $menuColumnGrandchild])</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</nav>
