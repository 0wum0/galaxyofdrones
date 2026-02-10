@extends('admin.layout')

@section('title', 'StarMap Management')

@section('content')
<div class="card">
    <h3>StarMap Statistics</h3>
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
            <div class="value">{{ number_format($stats['planets_occupied']) }}</div>
            <div class="label">Occupied</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($stats['starter_planets']) }}</div>
            <div class="label">Starter Slots</div>
        </div>
    </div>

    @if ($stats['stars'] === 0)
        <div class="alert alert-danger" style="margin-top:16px;">
            No starmap data! Generate a starmap to allow players to start the game.
        </div>
    @elseif ($stats['starter_planets'] === 0)
        <div class="alert alert-danger" style="margin-top:16px;">
            No starter planets available! Players will see "server is full". Regenerate the starmap.
        </div>
    @else
        <div class="alert alert-success" style="margin-top:16px;">
            StarMap is healthy. {{ $stats['starter_planets'] }} starter slots available for new players.
        </div>
    @endif
</div>

@if (!empty($starmapMeta))
<div class="card">
    <h3>StarMap Metadata</h3>
    <div class="table-wrap">
        <table>
            <tr><td>Generated</td><td><span class="badge {{ ($starmapMeta['generated'] ?? false) ? 'badge-green' : 'badge-red' }}">{{ ($starmapMeta['generated'] ?? false) ? 'Yes' : 'No' }}</span></td></tr>
            <tr><td>Generated at</td><td>{{ $starmapMeta['generated_at'] ?? 'Never' }}</td></tr>
        </table>
    </div>
</div>
@endif

<div class="card">
    <h3>Generate / Regenerate StarMap</h3>
    <p style="color:#8b949e;font-size:13px;margin-bottom:16px;">
        WARNING: Regenerating will DELETE all existing stars, planets and grids. Player-occupied planets will be removed!
    </p>

    <form method="POST" action="{{ route('admin.starmap.generate') }}">
        @csrf
        <div class="form-inline">
            <div class="form-group">
                <label for="stars">Number of Stars</label>
                <input type="number" id="stars" name="stars" value="2000" min="100" max="10000" style="width:200px;">
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('This will DELETE the entire starmap and regenerate it. All occupied planets will be lost. Are you sure?')">
                Regenerate StarMap
            </button>
        </div>
        <small style="color:#8b949e;">Each star generates ~3 planets. Recommended: 2000 stars for shared hosting.</small>
    </form>
</div>
@endsection
