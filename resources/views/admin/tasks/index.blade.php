@extends('admin.layout')

@section('title', __('Background tasks'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    @include('admin.tasks.partials.list')
@endsection
