{{--
    The shop's own points in the town the customer just chose — this module's
    part of "your town" (locations.dialog.sections, PLAN §38, the owner's
    mock-up): how many there are, the switch "stock at the chosen shop" (only
    when the shop lets the customer decide, `point_availability = choice`) and
    the points as cards to pick one. The fields ride the window's one form;
    `locations.dialog.saved` hears them. Named wh* — an @include hands a
    partial everything its caller has.
--}}
@if ($whPoints->isNotEmpty())
    <div class="border-t border-base-300 -mx-5 px-5 pt-4 flex flex-col gap-4" x-data="{ only: @js($whOnly) }">
        <div class="flex items-start gap-3 text-sm">
            <span class="w-9 h-9 shrink-0 rounded-field border border-success/50 text-success flex items-center justify-center"><x-icon name="info" /></span>
            <p class="opacity-80">
                {!! __('Shops of ours in this town: :count.', ['count' => '<b>'.$whPoints->count().'</b>']) !!}
                @if ($whChoice)
                    {{ __('You can have the catalogue show what is in stock at one of them.') }}
                @endif
            </p>
        </div>

        @if ($whChoice)
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="hidden" name="point_only" value="0">
                <input type="checkbox" name="point_only" value="1" class="toggle toggle-primary" x-model="only">
                <span class="font-medium">{{ __('Stock at the chosen shop') }}</span>
            </label>
        @endif

        <div class="flex flex-col gap-2" role="radiogroup" aria-label="{{ __('Your shop') }}">
            @foreach ($whPoints as $whPoint)
                <label class="flex items-start gap-3 p-4 rounded-box border-2 cursor-pointer transition-colors border-base-300 has-[:checked]:border-base-content">
                    <input type="radio" name="point_id" value="{{ $whPoint->id }}" class="radio radio-primary mt-0.5" @checked($whChosen === $whPoint->id)>
                    <span class="flex flex-col gap-0.5 min-w-0">
                        <span class="font-semibold">{{ $whPoint->t('name') }}</span>
                        @if ($whPoint->address)
                            <span class="text-sm opacity-80">{{ $whPoint->address }}</span>
                        @endif
                        @if ($whHours = $whPoint->hoursSummary())
                            <span class="text-xs opacity-60">
                                @foreach ($whHours as $whLine){{ $whLine['days'] }} {{ $whLine['time'] }}@if (! $loop->last), @endif @endforeach
                            </span>
                        @endif
                    </span>
                </label>
            @endforeach
            <label class="flex items-center gap-3 px-4 py-3 rounded-box border-2 cursor-pointer transition-colors border-base-300 has-[:checked]:border-base-content">
                <input type="radio" name="point_id" value="0" class="radio radio-primary" @checked($whChosen === null)>
                <span class="text-sm opacity-80">{{ __('No shop — show the whole catalogue') }}</span>
            </label>
        </div>
    </div>
@endif
