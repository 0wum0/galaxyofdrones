@extends('install.layout', ['step' => 3])

@section('page_title', 'Migration')

@section('content')
<div class="card">
    <h2>Step 3: Database Setup & Configuration</h2>
    <p style="color: #78909c; margin-bottom: 20px;">
        The .env file has been created, APP_KEY generated, and database migrations are running.
    </p>

    @if (!empty($errors) && count($errors) > 0)
        <div class="alert alert-danger">
            <strong>Errors occurred:</strong><br>
            @foreach ($errors as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    @if (!empty($results))
        <div class="log-output">@foreach ($results as $line){{ $line }}
@endforeach</div>
    @endif

    <div class="actions mt-2">
        @if (!empty($errors) && count($errors) > 0)
            <a href="{{ route('install.database') }}" class="btn btn-secondary">&larr; Back to Database</a>
            <a href="{{ route('install.migrate') }}" class="btn btn-primary">Retry</a>
        @else
            <div class="alert alert-success" style="flex: 1;">
                Database initialized successfully! .env written, APP_KEY generated, seeders ran.
            </div>
            <a href="{{ route('install.starmap') }}" class="btn btn-primary">Next: Generate StarMap &rarr;</a>
        @endif
    </div>
</div>
@endsection
