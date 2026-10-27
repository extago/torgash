@extends('admin.layout')

@section('title', __('Search the admin'))

@section('content')
    <form method="GET" action="{{ route('admin.search') }}" class="flex gap-2 max-w-2xl">
        <input type="search" name="q" value="{{ $query }}" autofocus
               class="input input-bordered w-full" placeholder="{{ __('Search screens and settings') }}">
        <button class="btn btn-primary">{{ __('Search') }}</button>
    </form>

    <div class="bg-base-100 border border-base-300 rounded-box max-w-2xl">
        @include('admin.search.results')
    </div>
@endsection
