<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin - Galaxy of Drones</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1724; color: #c9d1d9; min-height: 100vh; display: flex;
        }
        a { color: #58a6ff; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Sidebar */
        .sidebar {
            width: 240px; background: #161b22; border-right: 1px solid #21262d;
            min-height: 100vh; position: fixed; top: 0; left: 0; padding: 20px 0;
        }
        .sidebar-brand {
            padding: 0 20px 20px; border-bottom: 1px solid #21262d;
            font-size: 16px; font-weight: 700; color: #58a6ff;
        }
        .sidebar-brand small { display: block; font-size: 11px; color: #8b949e; font-weight: 400; }
        .sidebar-nav { padding: 12px 0; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 10px; padding: 10px 20px;
            color: #8b949e; font-size: 14px; transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            background: rgba(88,166,255,0.08); color: #c9d1d9;
            border-left-color: #58a6ff; text-decoration: none;
        }
        .sidebar-nav a.active { color: #58a6ff; }
        .sidebar-footer {
            position: absolute; bottom: 0; left: 0; right: 0;
            padding: 16px 20px; border-top: 1px solid #21262d; font-size: 12px; color: #484f58;
        }

        /* Main */
        .main { margin-left: 240px; flex: 1; min-height: 100vh; }
        .topbar {
            background: #161b22; border-bottom: 1px solid #21262d;
            padding: 14px 24px; display: flex; justify-content: space-between; align-items: center;
        }
        .topbar h1 { font-size: 18px; color: #c9d1d9; font-weight: 600; }
        .topbar-user { font-size: 13px; color: #8b949e; }
        .content { padding: 24px; }

        /* Cards */
        .card {
            background: #161b22; border: 1px solid #21262d; border-radius: 8px;
            padding: 20px; margin-bottom: 16px;
        }
        .card h3 {
            font-size: 14px; color: #8b949e; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 12px;
        }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
        .stat-card {
            background: #0d1117; border: 1px solid #21262d; border-radius: 8px;
            padding: 16px; text-align: center;
        }
        .stat-card .value { font-size: 28px; font-weight: 700; color: #58a6ff; }
        .stat-card .label { font-size: 12px; color: #8b949e; margin-top: 4px; }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 10px 14px; text-align: left; border-bottom: 1px solid #21262d; font-size: 14px;
        }
        th { color: #8b949e; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: rgba(88,166,255,0.04); }

        /* Badges */
        .badge { padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #0d3117; color: #3fb950; }
        .badge-red { background: #3d1117; color: #f85149; }
        .badge-blue { background: #0d2240; color: #58a6ff; }
        .badge-yellow { background: #3d2e00; color: #d29922; }

        /* Buttons */
        .btn {
            display: inline-block; padding: 6px 16px; border-radius: 6px; font-size: 13px;
            font-weight: 500; border: 1px solid #30363d; cursor: pointer; transition: all 0.2s;
            text-decoration: none;
        }
        .btn:hover { text-decoration: none; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-primary { background: #1f6feb; color: #fff; border-color: #1f6feb; }
        .btn-primary:hover { background: #388bfd; }
        .btn-danger { background: #da3633; color: #fff; border-color: #da3633; }
        .btn-danger:hover { background: #f85149; }
        .btn-outline { background: transparent; color: #c9d1d9; }
        .btn-outline:hover { background: #21262d; }

        /* Forms */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: #8b949e; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 8px 12px; background: #0d1117; border: 1px solid #30363d;
            color: #c9d1d9; border-radius: 6px; font-size: 14px;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none; border-color: #58a6ff; box-shadow: 0 0 0 3px rgba(31,111,235,0.2);
        }
        .form-inline { display: flex; gap: 8px; align-items: end; }
        .form-inline .form-group { margin-bottom: 0; }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #0d3117; border: 1px solid #238636; color: #3fb950; }
        .alert-danger { background: #3d1117; border: 1px solid #da3633; color: #f85149; }
        .alert-info { background: #0d2240; border: 1px solid #1f6feb; color: #58a6ff; }

        /* Pagination */
        .pagination { display: flex; gap: 4px; margin-top: 16px; justify-content: center; }
        .pagination a, .pagination span {
            padding: 6px 12px; border: 1px solid #30363d; border-radius: 6px;
            font-size: 13px; color: #8b949e;
        }
        .pagination a:hover { background: #21262d; text-decoration: none; }
        .pagination .active { background: #1f6feb; color: #fff; border-color: #1f6feb; }

        .log-box {
            background: #0d1117; border: 1px solid #21262d; border-radius: 6px;
            padding: 16px; font-family: monospace; font-size: 12px; color: #8b949e;
            max-height: 500px; overflow-y: auto; white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-brand">
            Galaxy of Drones
            <small>Admin Panel</small>
        </div>
        <nav class="sidebar-nav">
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                &#9632; Dashboard
            </a>
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                &#9632; Users
            </a>
            <a href="{{ route('admin.settings.index') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                &#9632; Game Settings
            </a>
            <a href="{{ route('admin.logs.index') }}" class="{{ request()->routeIs('admin.logs.*') ? 'active' : '' }}">
                &#9632; Logs
            </a>
            <a href="{{ route('admin.starmap.index') }}" class="{{ request()->routeIs('admin.starmap.*') ? 'active' : '' }}">
                &#9632; StarMap
            </a>
            <a href="{{ url('/') }}">
                &#9632; Back to Game
            </a>
        </nav>
        <div class="sidebar-footer">
            v{{ app()->version() }} / PHP {{ PHP_VERSION }}
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1>@yield('title', 'Dashboard')</h1>
            <div class="topbar-user">
                {{ auth()->user()->username ?? 'Admin' }}
                &middot;
                <a href="{{ route('logout') }}">Logout</a>
            </div>
        </header>

        <div class="content">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</body>
</html>
