{{--
    How long the set's price lasts: to Kit::countdownEndsAt(), the kit's own
    end, never "the end of the day" — a timer that runs out while the price
    stays is decoration. The instant goes out with its offset and the browser
    subtracts its own clock, so a customer two zones away sees the same
    minutes; a kept page stays right because nothing here is "now". At zero
    the line hides — the next click fetches the honest price.
--}}
<div x-data="{
        end: new Date(@js($until->toIso8601String())).getTime(),
        left: 0,
        tick() { this.left = Math.max(0, this.end - Date.now()); },
        part(ms) { return String(Math.floor(ms)).padStart(2, '0'); },
        get days() { return Math.floor(this.left / 86400000); },
        get hours() { return this.part((this.left % 86400000) / 3600000); },
        get minutes() { return this.part((this.left % 3600000) / 60000); },
        get seconds() { return this.part((this.left % 60000) / 1000); },
     }"
     x-init="tick(); setInterval(() => tick(), 1000)"
     x-show="left > 0" x-cloak
     class="flex flex-wrap items-center gap-2 mt-2">
    <span class="text-sm opacity-70">{{ __('The set\'s price lasts') }}</span>
    <span class="flex items-center gap-1 font-mono font-semibold tabular-nums">
        <template x-if="days > 0">
            <span class="px-2 py-1 rounded-md bg-base-200"><span x-text="days"></span>{{ __('d') }}</span>
        </template>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="hours"></span>
        <span class="opacity-40">:</span>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="minutes"></span>
        <span class="opacity-40">:</span>
        <span class="px-2 py-1 rounded-md bg-base-200" x-text="seconds"></span>
    </span>
</div>
