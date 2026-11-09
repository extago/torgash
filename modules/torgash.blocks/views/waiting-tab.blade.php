{{-- The name of a tab that waits, for a visitor with something to find under
     it. A personal part: drawn apart from the page, so it carries its own
     words and number. --}}
@if (\Torgash\Blocks\BlockTypes::hasLater($waitingType, $waitingSettings, $waitingKeys))
    <label class="torgash-tab">
        <input type="radio" name="{{ $waitingName }}" value="{{ $waitingValue }}" class="sr-only" @checked($waitingOpen)>
        <span>{{ $waitingLabel }}</span>
    </label>
@endif
