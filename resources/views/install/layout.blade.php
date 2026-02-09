<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Galaxy of Drones - Installer</title>
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
            display: flex; justify-content: center; gap: 8px; margin-bottom: 32px; flex-wrap: wrap;
        }
        .step-dot {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 600;
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
        .btn-block { display: block; width: 100%; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }
        .alert {
            padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
        }
        .alert-danger { background: rgba(183,28,28,0.2); border: 1px solid #c62828; color: #ef9a9a; }
        .alert-success { background: rgba(46,125,50,0.2); border: 1px solid #388e3c; color: #81c784; }
        .alert-info { background: rgba(13,71,161,0.2); border: 1px solid #1565c0; color: #90caf9; }
        .log-output {
            background: #0d1117; border: 1px solid #1e3a5f; border-radius: 8px;
            padding: 16px; font-family: 'Courier New', monospace; font-size: 13px;
            color: #81c784; max-height: 300px; overflow-y: auto; white-space: pre-wrap;
        }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 16px; }
        .spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid #42a5f5; border-top-color: transparent;
            border-radius: 50%; animation: spin 0.8s linear infinite;
            vertical-align: middle; margin-right: 8px;
        }
        @@keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="stars"></div>
    <div class="container">
        <div class="header">
            <h1>Galaxy of Drones</h1>
            <p>Installation Wizard</p>
        </div>

        <div class="steps">
            <div class="step-dot {{ $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' }}">1</div>
            <div class="step-dot {{ $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' }}">2</div>
            <div class="step-dot {{ $step >= 3 ? ($step > 3 ? 'done' : 'active') : '' }}">3</div>
            <div class="step-dot {{ $step >= 4 ? ($step > 4 ? 'done' : 'active') : '' }}">4</div>
            <div class="step-dot {{ $step >= 5 ? ($step > 5 ? 'done' : 'active') : '' }}">5</div>
        </div>

        @yield('content')
    </div>
</body>
</html>
