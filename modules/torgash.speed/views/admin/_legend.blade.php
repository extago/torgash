<div class="flex flex-wrap gap-x-4 gap-y-1 text-xs opacity-80">
    @foreach (\Torgash\Speed\Format::PARTS as $part => $label)
        <span><span class="speed-swatch" data-part="{{ $part }}"></span> {{ __($label) }}</span>
    @endforeach
</div>
