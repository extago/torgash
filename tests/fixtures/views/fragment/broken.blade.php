{{-- Fails half way, inside a loop and after pushing to a stack — the two
     pieces of view state a failure would otherwise leave behind. --}}
@push('fragment-scripts')
    <script>HALF-PUSHED</script>
@endpush
@foreach ([1, 2] as $brokenRow)
    <span>half-drawn {{ $brokenRow }}</span>
    @php throw new \RuntimeException('the block broke'); @endphp
@endforeach
