@extends('admin.layout')

@section('title', 'User Management')

@section('content')
<div class="card">
    <form method="GET" action="{{ route('admin.users.index') }}" class="form-inline" style="margin-bottom: 16px;">
        <div class="form-group" style="flex: 1;">
            <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search by username or email...">
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        @if ($search)
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline">Clear</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Admin</th>
                    <th>Started</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                <tr>
                    <td>{{ $user->id }}</td>
                    <td>
                        <a href="{{ route('admin.users.show', $user) }}">{{ $user->username }}</a>
                    </td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <span class="badge {{ $user->is_enabled ? 'badge-green' : 'badge-red' }}">
                            {{ $user->is_enabled ? 'Active' : 'Disabled' }}
                        </span>
                    </td>
                    <td>
                        <span class="badge {{ $user->is_admin ? 'badge-blue' : '' }}">
                            {{ $user->is_admin ? 'Admin' : '-' }}
                        </span>
                    </td>
                    <td>{{ $user->started_at ? $user->started_at->format('Y-m-d') : 'No' }}</td>
                    <td>{{ $user->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align: center; color: #484f58;">No users found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
    <div class="pagination">
        {{ $users->links('pagination::simple-bootstrap-4') }}
    </div>
    @endif
</div>
@endsection
