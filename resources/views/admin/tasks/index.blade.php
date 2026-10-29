@extends('admin.layout')

@section('title', __('Background tasks'))
@section('title_hint', __('Slow work the admin hands to the queue — imports, exports, rebuilds — with who started it, when, and how it ended. A task that hangs is cancelled here.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    @include('admin.tasks.partials.list')
@endsection
