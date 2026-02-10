<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Galaxy of Drones - @yield('page_title', 'Installer')</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #0a0e27;
            color: #e0e6ed;
            min-height: 100vh;
            line-height: 1.6;
        }
        .stars {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(ellipse at bottom, #1b2735 0%, #090a0f 100%);
            overflow: hidden; z-index: -1;
        }
        .stars::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background-image:
                radial-gradient(1px 1px at 20px 30px, #fff, transparent),
                radial-gradient(1px 1px at 40px 70px, rgba(255,255,255,0.8), transparent),
                radial-gradient(1px 1px at 90px 40px, #fff, transparent),
                radial-gradient(1px 1px at 130px 80px, rgba(255,255,255,0.6), transparent),
                radial-gradient(1px 1px at 160px 30px, #fff, transparent);
            background-size: 200px 100px;
            animation: twinkle 5s infinite alternate;
        }
        @@keyframes twinkle { from { opacity: 0.5; } to { opacity: 1; } }
        .container {
            max-width: 720px; margin: 0 auto; padding: 40px 20px;
        }
        .header {
            text-align: center; margin-bottom: 40px;
        }
        .header h1 {
            font-size: 2rem; color: #64b5f6;
            text-shadow: 0 0 20px rgba(100, 181, 246, 0.5);
        }
        .header p { color: #8899aa; margin-top: 8px; }
        .steps {
            display: flex; justify-content: center; gap: 6px; margin-bottom: 32px; flex-wrap: wrap;
        }
        .step-dot {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600;
            background: #1a2332; border: 2px solid #2a3a4a; color: #556;
            transition: all 0.3s;
        }
        .step-dot.active { background: #1565c0; border-color: #42a5f5; color: #fff; }
        .step-dot.done { background: #2e7d32; border-color: #66bb6a; color: #fff; }
        .card {
            background: rgba(20, 30, 48, 0.95); border: 1px solid #1e3a5f;
            border-radius: 12px; padding: 32px; margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        .card h2 {
            color: #64b5f6; font-size: 1.3rem; margin-bottom: 20px;
            padding-bottom: 12px; border-bottom: 1px solid #1e3a5f;
        }
        .check-list { list-style: none; }
        .check-list li {
            padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
            display: flex; justify-content: space-between; align-items: center;
        }
        .check-list li:last-child { border-bottom: none; }
        .badge {
            padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
        }
        .badge-ok { background: #1b5e20; color: #81c784; }
        .badge-fail { background: #b71c1c; color: #ef9a9a; }
        .badge-info { background: #0d47a1; color: #90caf9; }
        .badge-warn { background: #4a3500; color: #ffb74d; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; margin-bottom: 6px; color: #90a4ae; font-size: 14px; font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid #2a3a4a;
            background: #0d1b2a; color: #e0e6ed; border-radius: 8px;
            font-size: 15px; transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #42a5f5; }
        .btn {
            display: inline-block; padding: 12px 28px; border: none; border-radius: 8px;
            font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none;
            transition: all 0.3s; text-align: center;
        }
        .btn-primary { background: #1565c0; color: #fff; }
        .btn-primary:hover { background: #1976d2; box-shadow: 0 4px 12px rgba(21,101,192,0.4); }
        .btn-success { background: #2e7d32; color: #fff; }
        .btn-success:hover { background: #388e3c; }
        .btn-secondary { background: #37474f; color: #cfd8dc; }
        .btn-secondary:hover { background: #455a64; }
        .btn-danger { background: #b71c1c; color: #fff; }
        .btn-danger:hover { background: #c62828; }
        .btn-warning { background: #e65100; color: #fff; }
        .btn-warning:hover { background: #ef6c00; }
        .btn-block { display: block; width: 100%; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        .actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }
        .alert {
            padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
        }
        .alert-danger { background: rgba(183,28,28,0.2); border: 1px solid #c62828; color: #ef9a9a; }
        .alert-success { background: rgba(46,125,50,0.2); border: 1px solid #388e3c; color: #81c784; }
        .alert-info { background: rgba(13,71,161,0.2); border: 1px solid #1565c0; color: #90caf9; }
        .alert-warning { background: rgba(230,81,0,0.2); border: 1px solid #e65100; color: #ffb74d; }
        .log-output {
            background: #0d1117; border: 1px solid #1e3a5f; border-radius: 8px;
            padding: 16px; font-family: 'Courier New', monospace; font-size: 13px;
            color: #81c784; max-height: 300px; overflow-y: auto; white-space: pre-wrap;
        }
        .debug-box {
            background: rgba(74, 53, 0, 0.3); border: 1px solid #e65100; border-radius: 8px;
            padding: 16px; margin-bottom: 16px; font-family: 'Courier New', monospace;
            font-size: 12px; color: #ffb74d; max-height: 200px; overflow-y: auto;
            white-space: pre-wrap;
        }
        .debug-box summary { cursor: pointer; font-weight: 600; color: #ffb74d; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 16px; }
        .mt-3 { margin-top: 24px; }
        .mb-2 { margin-bottom: 16px; }
        .spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid #42a5f5; border-top-color: transparent;
            border-radius: 50%; animation: spin 0.8s linear infinite;
            vertical-align: middle; margin-right: 8px;
        }
        @@keyframes spin { to { transform: rotate(360deg); } }
        .stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #0d1b2a; border: 1px solid #1e3a5f; border-radius: 8px;
            padding: 16px; text-align: center;
        }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #64b5f6; }
        .stat-card .label { font-size: 11px; color: #8899aa; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="stars"></div>
    <div class="container">
        <div class="header">
            <h1>Galaxy of Drones</h1>
            <p>@yield('header_subtitle', 'Installation Wizard')</p>
        </div>

        @hasSection('steps')
            @yield('steps')
        @else
            <div class="steps">
                @for ($i = 1; $i <= 7; $i++)
                    <div class="step-dot {{ ($step ?? 0) >= $i ? (($step ?? 0) > $i ? 'done' : 'active') : '' }}">{{ $i }}</div>
                @endfor
            </div>
        @endif

        {{-- Debug info box: only visible when APP_DEBUG=true --}}
        @if (config('app.debug'))
            <details class="debug-box">
                <summary>Debug Info (APP_DEBUG=true)</summary>
                PHP: {{ PHP_VERSION }} | Laravel: {{ app()->version() }}
                Session Driver: {{ config('session.driver') }} | Cache: {{ config('cache.default') }}
                APP_URL: {{ config('app.url') }}
                Session Domain: {{ config('session.domain') ?: '(null/auto)' }}
                Session Secure: {{ var_export(config('session.secure'), true) }}
                Session SameSite: {{ config('session.same_site') }}
                Request Secure: {{ request()->isSecure() ? 'yes' : 'no' }}
                Request URL: {{ request()->fullUrl() }}
                @if (file_exists(storage_path('app/installer_state.json')))
                Installer State: {{ @file_get_contents(storage_path('app/installer_state.json')) }}
                @endif
            </details>
        @endif

        @yield('content')
    </div>
</body>
</html>
