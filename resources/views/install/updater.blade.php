@extends('install.layout', ['step' => 0])

@section('page_title', 'Update & Repair')
@section('header_subtitle', 'Update & Repair Tools')

@section('steps')
    {{-- No step dots for updater mode --}}
@endsection

@section('content')

@if (session('updater_results'))
    @foreach (session('updater_results') as $result)
        <div class="alert alert-success">{{ $result }}</div>
    @endforeach
@endif

@if (session('updater_errors'))
    @foreach (session('updater_errors') as $error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endforeach
@endif

@if (!empty($dbError))
    <div class="alert alert-danger">
        <strong>Database Connection Error:</strong> {{ $dbError }}<br>
        <small>Check your .env file DB_* settings or use the "Clear Caches" button below.</small>
    </div>
@endif

<div class="card">
    <h2>System Status</h2>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="value">{{ number_format($stats['stars']) }}</div>
            <div class="label">Stars</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($stats['planets']) }}</div>
            <div class="label">Planets</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($stats['starter_planets']) }}</div>
            <div class="label">Starter Slots</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($stats['users']) }}</div>
            <div class="label">Users</div>
        </div>
    </div>

    @if ($stats['stars'] === 0)
        <div class="alert alert-warning">
            No starmap data found! Generate a starmap to allow players to start.
        </div>
    @elseif ($stats['starter_planets'] === 0)
        <div class="alert alert-warning">
            No starter planets available! Players will see "server is full". Regenerate the starmap.
        </div>
    @else
        <div class="alert alert-success">
            System is operational. {{ $stats['starter_planets'] }} starter slots available.
        </div>
    @endif
</div>

<div class="card">
    <h2>Database Tools</h2>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="display:inline;">
            @csrf
            <input type="hidden" name="action" value="migrate">
            <button type="submit" class="btn btn-primary btn-sm">Run Migrations</button>
        </form>

        <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="display:inline;">
            @csrf
            <input type="hidden" name="action" value="seed">
            <button type="submit" class="btn btn-primary btn-sm">Run Seeders</button>
        </form>

        <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="display:inline;">
            @csrf
            <input type="hidden" name="action" value="passport">
            <button type="submit" class="btn btn-secondary btn-sm">Reinstall Passport</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Cache Tools</h2>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="display:inline;">
            @csrf
            <input type="hidden" name="action" value="cache">
            <button type="submit" class="btn btn-primary btn-sm">Clear All Caches</button>
        </form>

        <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="display:inline;">
            @csrf
            <input type="hidden" name="action" value="clear_sessions">
            <button type="submit" class="btn btn-warning btn-sm">Clear Sessions & Cache</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>StarMap Tools</h2>

    <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}" style="margin-bottom: 16px;">
        @csrf
        <input type="hidden" name="action" value="generate_starmap">
        <div style="display: flex; gap: 12px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0; flex: 1;">
                <label for="stars_gen">Stars (full regenerate)</label>
                <input type="number" id="stars_gen" name="stars" value="2000" min="100" max="5000">
            </div>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('WARNING: This will DELETE all existing starmap data including occupied planets. Continue?')">
                Regenerate Map
            </button>
        </div>
        <small style="color: #546e7a;">Warning: Regenerating deletes ALL stars, planets and grids. Occupied planets will be lost!</small>
    </form>

    <hr style="border-color: #1e3a5f; margin: 16px 0;">

    <form method="POST" action="{{ route('install.run_update', request()->only('token')) }}">
        @csrf
        <input type="hidden" name="action" value="expand_starmap">
        <div style="display: flex; gap: 12px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0; flex: 1;">
                <label for="stars_expand">Additional Stars (expand)</label>
                <input type="number" id="stars_expand" name="stars" value="500" min="100" max="2000">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                Expand Map
            </button>
        </div>
        <small style="color: #546e7a;">Adds more stars/planets without deleting existing ones. Use this if you need more starter slots.</small>
    </form>
</div>

<div class="actions mt-2">
    <a href="{{ url('/') }}" class="btn btn-success">Back to Game</a>
    <a href="{{ url('/admin') }}" class="btn btn-primary">Admin Panel</a>
</div>

@endsection
