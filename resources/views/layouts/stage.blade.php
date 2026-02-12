@extends('layouts.main')

@section('body')
    <section id="app" class="container-app">
        @include('partials.sidebar')
        @include('partials.player')
        @include('partials.monitor')

        @yield('content')

        @include('partials.mailbox')
        @include('partials.message')
        @include('partials.mothership')
        @include('partials.profile')
        @include('partials.setting')
        @include('partials.trophy')
    </section>
@endsection

@push('head-extra')
    {{-- Prevent browser from asking "resend POST data" on refresh.
         The no-cache headers ensure the browser always does a fresh GET. --}}
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
@endpush
