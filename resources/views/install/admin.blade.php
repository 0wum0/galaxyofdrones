@extends('install.layout', ['step' => 4])

@section('content')
<div class="card">
    <h2>Step 4: Create Admin Account</h2>
    <p style="color: #78909c; margin-bottom: 20px;">
        Create the administrator account for managing the game.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('install.create_admin') }}">
        @csrf

        <div class="form-group">
            <label for="username">Admin Username</label>
            <input type="text" id="username" name="username" value="{{ old('username', 'admin') }}" required minlength="3" maxlength="20">
        </div>

        <div class="form-group">
            <label for="email">Admin Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', '') }}" required placeholder="admin@yourdomain.com">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8" placeholder="Minimum 8 characters">
        </div>

        <div class="form-group">
            <label for="password_confirmation">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8">
        </div>

        <div class="actions">
            <span></span>
            <button type="submit" class="btn btn-primary">Create Admin & Finish &rarr;</button>
        </div>
    </form>
</div>
@endsection
