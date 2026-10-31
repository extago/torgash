{{--
    The timer a sale page and a product page both show.

    It counts to ONE instant handed in as $until — Promotion::countdownEndsAt(),
    which is already whichever comes first, tonight's window closing or the sale
    itself ending. Nothing here decides that: a timer that runs out while the
    price stays the same, or keeps running after the price goes back up, teaches
    customers that the timer is decoration.

    The instant is written out in ISO 8601 WITH its offset, and the browser
    subtracts it from its own clock: the shop's timezone is the shop's business,
    and a customer two zones away must still see the same number of minutes
    left, not the shop's wall clock.

    When it reaches zero the block simply disappears (x-show) rather than
    reloading the page — the page's prices were rendered before the deadline and
    the visitor's next click will fetch the honest ones. Auto-reloading a page
    someone is reading is a worse trade than a one-click-stale price.

    `$countdownBoxes` draws it large — four cells, each with its word under the
    number (the deal block); `$countdownPill` small, for a card's photo among
    its labels ("5d 03:57", seconds only on the last day); the default is the
    compact line.
--}}
<div x-data="{
        end: new Date(@js($until->toIso8601String())).getTime(),
        left: 0,
        tick() { this.left = Math.max(0, this.end - Date.now()); },
        part(ms) { return String(Math.floor(ms)).padStart(2, '0'); },
        get days() { return Math.floor(this.left / 86400000); },
        get dd() { return this.part(this.days); },
        get hours() { return this.part((this.left % 86400000) / 3600000); },
        get minutes() { return this.part((this.left % 3600000) / 60000); },
        get seconds() { return this.part((this.left % 60000) / 1000); },
     }"
     x-init="tick(); setInterval(() => tick(), 1000)"
     x-show="left > 0" x-cloak
     class="flex items-center gap-2 {{ $class ?? '' }}">
    @if (! empty($label))
        <span class="text-sm opacity-70">{{ $label }}</span>
    @endif
    @if (! empty($countdownPill))
        {{-- What the time counts down to is core's hint (<x-popover hover>).
             pointer-events-auto: on a card the pill stands among the labels,
             whose corner lets clicks through to the photo. --}}
        <x-popover hover class="torgash-countdown-pill pointer-events-auto">
            {{ __('Until the sale ends') }}
            <x-slot:trigger>
                <x-icon name="zap" size="sm" />
                {{-- One flex item for the time: the colons must not get the gap. --}}
                <span x-show="days > 0"><span x-text="days"></span>{{ __('d') }}</span>
                <span><span x-text="hours"></span>:<span x-text="minutes"></span><span x-show="days === 0">:<span x-text="seconds"></span></span></span>
            </x-slot:trigger>
        </x-popover>
    @elseif (! empty($countdownBoxes))
        <div class="torgash-countdown-boxes">
            @foreach (['days' => __('d.'), 'hours' => __('h.'), 'minutes' => __('min.'), 'seconds' => __('sec.')] as $countdownUnit => $countdownWord)
                {{-- No "00 days" cell: on the last day the hours lead. --}}
                <span @if ($countdownUnit === 'days') x-show="days > 0" @endif><b x-text="{{ $countdownUnit === 'days' ? 'dd' : $countdownUnit }}"></b><small>{{ $countdownWord }}</small></span>
            @endforeach
        </div>
    @else
    <div class="flex items-center gap-1 font-mono font-semibold tabular-nums">
        <template x-if="days > 0">
            <span class="px-2 py-1 rounded-md bg-base-200"><span x-text="days"></span>{{ __('d') }}</span>
        </template>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="hours"></span>
        <span class="opacity-40">:</span>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="minutes"></span>
        <span class="opacity-40">:</span>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="seconds"></span>
    </div>
    @endif
</div>
