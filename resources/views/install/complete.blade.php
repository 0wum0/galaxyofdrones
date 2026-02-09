@extends('install.layout', ['step' => 5])

@section('content')
<div class="card">
    <h2 style="color: #66bb6a;">Installation Complete!</h2>

    <div class="alert alert-success">
        Galaxy of Drones has been successfully installed. The installer is now locked.
    </div>

    <h3 style="color: #90a4ae; font-size: 14px; margin: 20px 0 12px; text-transform: uppercase; letter-spacing: 1px;">Next Steps</h3>

    <ul class="check-list">
        <li>
            <span>Set up a Cron Job (required for game mechanics)</span>
            <span class="badge badge-info">Important</span>
        </li>
    </ul>

    <div style="margin-top: 16px;">
        <p style="color: #78909c; margin-bottom: 8px;">Add this to your Hostinger Cron Jobs (hPanel > Advanced > Cron Jobs):</p>
        <div class="log-output" style="font-size: 12px;">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</div>
    </div>

    @if ($cronToken)
    <div style="margin-top: 16px;">
        <p style="color: #78909c; margin-bottom: 8px;">Alternative: HTTP Cron Endpoint (if shell cron is not available):</p>
        <div class="log-output" style="font-size: 12px;">GET {{ url('/cron/tick') }}?token={{ trimQuotes($cronToken) }}</div>
        <p style="color: #546e7a; font-size: 12px; margin-top: 8px;">
            Use an external cron service (e.g. cron-job.org) to call this URL every minute.
        </p>
    </div>
    @endif

    <div style="margin-top: 24px;">
        <h3 style="color: #90a4ae; font-size: 14px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">Generate Starmap</h3>
        <p style="color: #78909c; margin-bottom: 8px;">Run this command via SSH to generate the game world:</p>
        <div class="log-output" style="font-size: 12px;">cd {{ base_path() }} && php artisan starmap:generate</div>
    </div>

    <div class="actions mt-2">
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
