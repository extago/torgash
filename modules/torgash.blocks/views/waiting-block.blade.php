{{-- A block that waits and stands alone: its place, for a visitor with
     something to put in it. A personal part: drawn apart from the page. --}}
@if (\Torgash\Blocks\BlockTypes::hasLater($waitingType, $waitingSettings, $waitingKeys))
    @include('torgash.blocks::waiting')
@endif
