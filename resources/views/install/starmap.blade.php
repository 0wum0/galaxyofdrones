@extends('install.layout', ['step' => 4])

@section('page_title', 'StarMap')

@section('content')
<div class="card">
    <h2>Step 4: Generate StarMap</h2>
    <p style="color: #78909c; margin-bottom: 20px;">
        The game world needs stars and planets for players to colonize. This step generates the galaxy map.
    </p>

    @if (!empty($errors ?? []))
        @foreach ($errors as $error)
            <div class="alert alert-danger">{{ $error }}</div>
        @endforeach
    @endif

    @if (!empty($results ?? []))
        <div class="log-output mb-2">@foreach ($results as $line){{ $line }}
@endforeach</div>
    @endif

    @if ($starmapExists)
        <div class="stat-grid">
            <div class="stat-card">
                <div class="value">{{ number_format($starCount) }}</div>
                <div class="label">Stars</div>
            </div>
            <div class="stat-card">
                <div class="value">{{ number_format($planetCount) }}</div>
                <div class="label">Planets</div>
            </div>
            <div class="stat-card">
                <div class="value">{{ number_format($starterCount) }}</div>
                <div class="label">Starter Slots</div>
            </div>
        </div>

        @if ($starterCount > 0)
            <div class="alert alert-success">
                StarMap is ready! {{ $starterCount }} starter planets available for new players.
            </div>
        @else
            <div class="alert alert-warning">
                StarMap exists but has no starter planets! Players will see "server is full".
                Try regenerating with more planets.
            </div>
        @endif

        <div class="actions mt-2">
            <form method="POST" action="{{ route('install.generate_starmap') }}" onsubmit="return confirm('This will DELETE the existing starmap and generate a new one. Continue?')">
                @csrf
                <input type="hidden" name="clear" value="1">
                <input type="hidden" name="stars" value="2000">
                <button type="submit" class="btn btn-warning btn-sm">Regenerate Map</button>
            </form>
            <a href="{{ route('install.admin') }}" class="btn btn-primary">Next: Create Admin &rarr;</a>
        </div>
    @else
        <div class="alert alert-info">
            No starmap found. Generate one now to allow players to start the game.
        </div>

        <form method="POST" action="{{ route('install.generate_starmap') }}" id="starmapForm">
            @csrf

            <div class="form-group">
                <label for="stars">Number of Stars (100 - 5000)</label>
                <input type="number" id="stars" name="stars" value="2000" min="100" max="5000">
                <small style="color: #546e7a;">Recommended: 2000 for shared hosting. Each star gets ~3 planets.</small>
            </div>

            <div class="actions">
                <a href="{{ route('install.admin') }}" class="btn btn-secondary">Skip (not recommended)</a>
                <button type="submit" class="btn btn-primary" id="genBtn" onclick="this.disabled=true;this.innerHTML='<span class=\'spinner\'></span> Generating... (this may take 1-3 minutes)';this.form.submit();">
                    Generate StarMap &rarr;
                </button>
            </div>
        </form>
    @endif
</div>
@endsection
