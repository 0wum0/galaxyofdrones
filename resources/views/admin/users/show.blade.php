@extends('admin.layout')

@section('title', 'User: ' . $user->username)

@section('content')
<div style="margin-bottom: 16px;">
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline">&larr; Back to Users</a>
</div>

<div class="card">
    <h3>User Details</h3>
    <div class="table-wrap">
        <table>
            <tr><td style="width: 200px;">ID</td><td>{{ $user->id }}</td></tr>
            <tr><td>Username</td><td>{{ $user->username }}</td></tr>
            <tr><td>Email</td><td>{{ $user->email }}</td></tr>
            <tr>
                <td>Status</td>
                <td>
                    <span class="badge {{ $user->is_enabled ? 'badge-green' : 'badge-red' }}">
                        {{ $user->is_enabled ? 'Active' : 'Disabled' }}
                    </span>
                </td>
            </tr>
            <tr>
                <td>Admin</td>
                <td>
                    <span class="badge {{ $user->is_admin ? 'badge-blue' : '' }}">
                        {{ $user->is_admin ? 'Yes' : 'No' }}
                    </span>
                </td>
            </tr>
            <tr><td>Email Verified</td><td>{{ $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i') : 'Not verified' }}</td></tr>
            <tr><td>Energy</td><td>{{ number_format($user->energy) }}</td></tr>
            <tr><td>Solarion</td><td>{{ number_format($user->solarion) }}</td></tr>
            <tr><td>Experience</td><td>{{ number_format($user->experience) }}</td></tr>
            <tr><td>Production Rate</td><td>{{ number_format($user->production_rate) }}</td></tr>
            <tr><td>Penalty Rate</td><td>{{ $user->penalty_rate }}</td></tr>
            <tr><td>Started at</td><td>{{ $user->started_at ?? 'Not started' }}</td></tr>
            <tr><td>Last Login</td><td>{{ $user->last_login ?? 'Never' }}</td></tr>
            <tr><td>Registered</td><td>{{ $user->created_at->format('Y-m-d H:i:s') }}</td></tr>
        </table>
    </div>
</div>

<div class="card">
    <h3>Actions</h3>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <form method="POST" action="{{ route('admin.users.toggle_admin', $user) }}">
            @csrf
            @method('PUT')
            <button type="submit" class="btn {{ $user->is_admin ? 'btn-danger' : 'btn-primary' }}" onclick="return confirm('Are you sure?')">
                {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
            </button>
        </form>

        <form method="POST" action="{{ route('admin.users.toggle_enabled', $user) }}">
            @csrf
            @method('PUT')
            <button type="submit" class="btn {{ $user->is_enabled ? 'btn-danger' : 'btn-primary' }}" onclick="return confirm('Are you sure?')">
                {{ $user->is_enabled ? 'Disable User' : 'Enable User' }}
            </button>
        </form>
    </div>
</div>

@if ($user->planets && $user->planets->count() > 0)
<div class="card">
    <h3>Planets ({{ $user->planets->count() }})</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Coordinates</th>
                    <th>Size</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($user->planets as $planet)
                <tr>
                    <td>{{ $planet->id }}</td>
                    <td>{{ $planet->custom_name ?? $planet->name }}</td>
                    <td>{{ $planet->x }}, {{ $planet->y }}</td>
                    <td>{{ $planet->size }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
