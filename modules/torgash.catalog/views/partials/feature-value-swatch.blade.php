@if ($feature->is_color && $value->swatch)
    <span class="w-3 h-3 rounded-full inline-block border border-base-300 shrink-0"
          style="background:{{ $value->swatch }}" title="{{ $value->display() }}"></span>
@endif
