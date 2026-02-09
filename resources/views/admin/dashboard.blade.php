@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
<div class="stat-grid">
    <div class="stat-card">
        <div class="value">{{ $stats['users'] }}</div>
        <div class="label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="value">{{ $stats['users_started'] }}</div>
        <div class="label">Active Players</div>
    </div>
    <div class="stat-card">
        <div class="value">{{ $stats['stars'] }}</div>
        <div class="label">Stars</div>
    </div>
    <div class="stat-card">
        <div class="value">{{ $stats['planets'] }}</div>
        <div class="label">Planets</div>
    </div>
    <div class="stat-card">
        <div class="value">{{ $stats['planets_occupied'] }}</div>
        <div class="label">Occupied Planets</div>
    </div>
    <div class="stat-card">
        <div class="value">{{ $stats['movements_pending'] }}</div>
        <div class="label">Active Movements</div>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <h3>Pending Game Events</h3>
    <div class="table-wrap">
        <table>
            <tr>
                <td>Constructions</td>
                <td><span class="badge {{ $stats['constructions_pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">{{ $stats['constructions_pending'] }}</span></td>
            </tr>
            <tr>
                <td>Upgrades</td>
                <td><span class="badge {{ $stats['upgrades_pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">{{ $stats['upgrades_pending'] }}</span></td>
            </tr>
            <tr>
                <td>Trainings</td>
                <td><span class="badge {{ $stats['trainings_pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">{{ $stats['trainings_pending'] }}</span></td>
            </tr>
            <tr>
                <td>Research</td>
                <td><span class="badge {{ $stats['research_pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">{{ $stats['research_pending'] }}</span></td>
            </tr>
            <tr>
                <td>Movements</td>
                <td><span class="badge {{ $stats['movements_pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">{{ $stats['movements_pending'] }}</span></td>
            </tr>
        </table>
    </div>
</div>

@if ($installInfo)
<div class="card">
    <h3>Installation Info</h3>
    <div class="table-wrap">
        <table>
            <tr><td>Installed at</td><td>{{ $installInfo['installed_at'] ?? 'Unknown' }}</td></tr>
            <tr><td>PHP Version</td><td>{{ $installInfo['php_version'] ?? PHP_VERSION }}</td></tr>
            <tr><td>Laravel Version</td><td>{{ $installInfo['laravel_version'] ?? app()->version() }}</td></tr>
            <tr><td>Server Software</td><td>{{ $_SERVER['SERVER_SOFTWARE'] ?? 'CLI' }}</td></tr>
        </table>
    </div>
</div>
@endif
@endsection
