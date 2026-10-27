@extends('fragtest::fragment.layout')

@section('content')
    <p>THE-CONTENT</p>
@endsection

@push('fragment-scripts')
    <script>THE-SCRIPT</script>
@endpush
