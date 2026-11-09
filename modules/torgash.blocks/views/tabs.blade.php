{{-- A row of tabs made of the blocks standing next to each other in a zone —
     see Torgash\Blocks\ZoneRenderer. Radio inputs rather than Alpine: the
     panel a visitor picks is a form state the browser already knows how to
     keep, so this works before (and without) any script. One member is just
     a heading over its content: a tab strip with one tab in it is not a
     choice. No ARIA tab roles: a group of named radios already says "one of
     these" to a screen reader.

     The names stand in a strip of their own that scrolls sideways and never
     wraps — on a phone four names do not fit, and a second line of tabs reads
     as a second row of choices. The panels are below it, so the strip cannot
     reach them with a sibling selector; app.css shows the panel whose number
     the checked radio carries (`:has()`, "Zone tabs").

     A tab that waits (ZoneRenderer) brings its own name, a personal part that
     prints nothing for a visitor with nothing under it — its panel, the same
     for everybody, is then simply never shown. A row with one is always a
     row: for most visitors that is one name, which app.css draws as the
     heading above. --}}
<section class="mb-10">
    @if (count($tabs) === 1 && empty($tabs[0]['waits']))
        <h2 class="text-lg font-semibold mb-4">{{ $tabs[0]['label'] }}</h2>
        {!! $tabs[0]['html'] !!}
    @else
        <div class="torgash-tabs">
            <div class="torgash-tabs-bar">
                @foreach ($tabs as $i => $tab)
                    @if (! empty($tab['waits']))
                        {!! $tab['bar'] !!}
                    @else
                        <label class="torgash-tab">
                            <input type="radio" name="{{ $name }}" value="{{ $i }}" class="sr-only" @checked($i === $open)>
                            <span>{{ $tab['label'] }}</span>
                        </label>
                    @endif
                @endforeach
            </div>
            @foreach ($tabs as $i => $tab)
                <div class="torgash-tab-panel pt-4" data-tab-panel="{{ $i }}">
                    {!! $tab['html'] !!}
                </div>
            @endforeach
        </div>
    @endif
</section>
