@extends('install.layout', ['step' => 7])

@section('page_title', 'Complete')

@section('content')
<div class="card">
    <h2 style="color: #66bb6a;">Installation Complete!</h2>

    <div class="alert alert-success">
        Galaxy of Drones has been successfully installed. The installer is now locked.
    </div>

    <h3 style="color: #90a4ae; font-size: 14px; margin: 20px 0 12px; text-transform: uppercase; letter-spacing: 1px;">Cron Job Setup</h3>

    <div style="margin-top: 16px;">
        <p style="color: #78909c; margin-bottom: 8px;">
            <strong>Option A:</strong> Shell Cron (recommended, if SSH available):
        </p>
        <div class="log-output" style="font-size: 12px;">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</div>
    </div>

    @if ($cronToken)
    <div style="margin-top: 16px;">
        <p style="color: #78909c; margin-bottom: 8px;">
            <strong>Option B:</strong> HTTP Cron Endpoint (if shell cron is not available):
        </p>
        <div class="log-output" style="font-size: 12px;">GET {{ url('/cron/tick') }}?token={{ trimQuotes($cronToken) }}</div>
        <p style="color: #546e7a; font-size: 12px; margin-top: 8px;">
            Use your hosting control panel or an external service (e.g. cron-job.org) to call this URL every minute.
        </p>
    </div>
    @endif

    @if (!empty($installToken))
    <div style="margin-top: 16px;">
        <h3 style="color: #90a4ae; font-size: 14px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">Installer Unlock Token</h3>
        <p style="color: #78909c; margin-bottom: 8px;">
            To re-access the installer for updates/repairs, use this token:
        </p>
        <div class="log-output" style="font-size: 12px;">{{ url('/install') }}?token={{ trimQuotes($installToken) }}</div>
        <p style="color: #546e7a; font-size: 12px; margin-top: 8px;">
            Alternatively, create a file <code>storage/install.unlock</code> to unlock.
        </p>
    </div>
    @endif

    <div class="actions mt-3">
        <a href="{{ url('/') }}" class="btn btn-success btn-block" style="text-align: center;">
            Launch Galaxy of Drones &rarr;
        </a>
    </div>

    <div style="margin-top: 16px;">
        <a href="{{ url('/admin') }}" class="btn btn-primary btn-block" style="text-align: center;">
            Go to Admin Panel
        </a>
    </div>
</div>
@endsection
