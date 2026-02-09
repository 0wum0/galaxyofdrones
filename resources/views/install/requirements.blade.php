@extends('install.layout', ['step' => 1])

@section('content')
<div class="card">
    <h2>Step 1: System Requirements</h2>

    <h3 style="color: #90a4ae; font-size: 14px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">PHP & Extensions</h3>
    <ul class="check-list">
        @foreach ($requirements as $req)
        <li>
            <span>{{ $req['name'] }}</span>
            <span>
                <span class="badge {{ $req['passed'] ? 'badge-ok' : 'badge-fail' }}">
                    {{ $req['current'] }}
                </span>
            </span>
        </li>
        @endforeach
    </ul>
</div>

<div class="card">
    <h3 style="color: #90a4ae; font-size: 14px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">Directory Permissions</h3>
    <ul class="check-list">
        @foreach ($permissions as $perm)
        <li>
            <span>{{ $perm['name'] }}</span>
            <span>
                <span class="badge {{ $perm['passed'] ? 'badge-ok' : 'badge-fail' }}">
                    {{ $perm['current'] }}
                </span>
            </span>
        </li>
        @endforeach
    </ul>
</div>

<div class="actions">
    <span></span>
    @if ($allPassed)
        <a href="{{ route('install.database') }}" class="btn btn-primary">Next: Database Setup &rarr;</a>
    @else
        <div>
            <div class="alert alert-danger">
                Please fix the failed requirements before continuing.
                <br>Run: <code>chmod -R 775 storage bootstrap/cache</code>
            </div>
            <a href="{{ route('install.index') }}" class="btn btn-secondary">Re-check</a>
        </div>
    @endif
</div>
@endsection
