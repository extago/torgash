{{-- The five parts of a request, colored by role. The first four are the
     reference palette's slots 1-4 in stack order (validated: adjacent CVD
     separation >= 8.4 in both modes); "code & templates" is neutral gray,
     since it is what nobody claimed rather than a fifth kind of thing. Two
     light steps are under 3:1 on the surface, so every bar carries its numbers
     in a tooltip and every page with a bar has the same numbers as text. --}}
<style>
    .speed-viz {
        --speed-boot: #2a78d6;
        --speed-db: #eb6834;
        --speed-http: #1baf7a;
        --speed-hooks: #eda100;
        --speed-rest: #a8a7a0;
        --speed-track: color-mix(in oklab, currentColor 8%, transparent);
    }
    [data-theme="torgash-dark"] .speed-viz {
        --speed-boot: #3987e5;
        --speed-db: #d95926;
        --speed-http: #199e70;
        --speed-hooks: #c98500;
        --speed-rest: #6b6a64;
    }
    .speed-viz .speed-seg { height: 100%; }
    .speed-viz .speed-seg:first-child { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }
    .speed-viz .speed-seg:last-child { border-top-right-radius: 4px; border-bottom-right-radius: 4px; }
    .speed-viz .speed-seg + .speed-seg { margin-left: 2px; }
    .speed-viz .speed-swatch { display: inline-block; width: .7rem; height: .7rem; border-radius: 3px; vertical-align: -1px; }
    .speed-viz [data-part="boot"] { background: var(--speed-boot); }
    .speed-viz [data-part="db"] { background: var(--speed-db); }
    .speed-viz [data-part="http"] { background: var(--speed-http); }
    .speed-viz [data-part="hooks"] { background: var(--speed-hooks); }
    .speed-viz [data-part="rest"] { background: var(--speed-rest); }
    .speed-viz .speed-hit:hover { background: color-mix(in oklab, currentColor 5%, transparent); }
</style>
