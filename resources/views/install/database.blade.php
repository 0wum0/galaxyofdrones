@extends('install.layout', ['step' => 2])

@section('page_title', 'Database')

@section('content')
<div class="card">
    <h2>Step 2: Database Configuration</h2>
    <p style="color: #78909c; margin-bottom: 20px;">
        Enter your MySQL database credentials. You can find these in your hosting control panel.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Error:</strong>
            <ul style="margin:6px 0 0 18px;padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <noscript>
        <div class="alert alert-warning">
            <strong>JavaScript disabled:</strong> The installer works best with JavaScript enabled.
            Without it, form submission relies on POST which may have issues on some shared hosting.
        </div>
    </noscript>

    <div id="postWarning" style="display:none;" class="alert alert-warning">
        <strong>Note:</strong> Your hosting may have issues with form submissions (POST requests).
        The AJAX "Test Connection" button is more reliable. Click "Test Connection" first,
        then click "Save &amp; Continue" to proceed.
    </div>

    <form method="POST" action="{{ route('install.test_database') }}" id="dbForm">
        {{-- CSRF token included for compatibility. The installer middleware group
             does NOT verify CSRF, but including it doesn't hurt and ensures
             the form works if someone re-enables CSRF for installer routes. --}}
        @csrf

        <div class="form-group">
            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host"
                   value="{{ old('db_host', $defaults['db_host'] ?? 'localhost') }}" required>
        </div>

        <div class="form-group">
            <label for="db_port">Database Port</label>
            <input type="number" id="db_port" name="db_port"
                   value="{{ old('db_port', $defaults['db_port'] ?? '3306') }}" required>
        </div>

        <div class="form-group">
            <label for="db_database">Database Name</label>
            <input type="text" id="db_database" name="db_database"
                   value="{{ old('db_database', $defaults['db_database'] ?? '') }}" required
                   placeholder="e.g. u123456789_galaxy">
        </div>

        <div class="form-group">
            <label for="db_username">Database Username</label>
            <input type="text" id="db_username" name="db_username"
                   value="{{ old('db_username', $defaults['db_username'] ?? '') }}" required
                   placeholder="e.g. u123456789_admin">
        </div>

        <div class="form-group">
            <label for="db_password">Database Password</label>
            <input type="password" id="db_password" name="db_password"
                   value="{{ old('db_password', $defaults['db_password'] ?? '') }}"
                   placeholder="Your database password">
        </div>

        <div id="testResult" style="display:none;" class="alert"></div>

        <div class="actions">
            <button type="button" class="btn btn-secondary" id="testBtn" onclick="testConnection()">
                Test Connection
            </button>
            <button type="submit" class="btn btn-primary" id="submitBtn">
                Save &amp; Continue &rarr;
            </button>
        </div>
    </form>
</div>

<script>
var dbTestPassed = false;

function testConnection() {
    var btn = document.getElementById('testBtn');
    var result = document.getElementById('testResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Testing...';
    result.style.display = 'none';

    var data = {
        db_host: document.getElementById('db_host').value,
        db_port: document.getElementById('db_port').value,
        db_database: document.getElementById('db_database').value,
        db_username: document.getElementById('db_username').value,
        db_password: document.getElementById('db_password').value,
    };

    fetch('{{ route("install.test_database") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(function(r) {
        if (r.status === 419) {
            throw new Error('Session expired (419). Please reload the page and try again.');
        }
        if (r.status === 500) {
            throw new Error('Server error (500). Check storage/logs/laravel.log for details.');
        }
        if (r.redirected) {
            throw new Error('Server redirected the request (possible hosting issue). The form submission route may not be reachable via POST. Status: ' + r.status);
        }
        if (!r.ok) {
            throw new Error('Request failed with status ' + r.status);
        }
        return r.json();
    })
    .then(function(resp) {
        result.style.display = 'block';
        result.className = 'alert ' + (resp.success ? 'alert-success' : 'alert-danger');
        result.textContent = resp.message;
        if (resp.success) {
            dbTestPassed = true;
        }
    })
    .catch(function(e) {
        result.style.display = 'block';
        result.className = 'alert alert-danger';
        result.textContent = e.message || 'Connection test failed. Please check your details.';
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
    });
}

// Intercept form submit: submit via AJAX first to save credentials to state,
// then redirect via GET if the POST route has issues on this hosting.
document.getElementById('dbForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var submitBtn = document.getElementById('submitBtn');
    var result = document.getElementById('testResult');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> Saving...';

    var data = {
        db_host: document.getElementById('db_host').value,
        db_port: document.getElementById('db_port').value,
        db_database: document.getElementById('db_database').value,
        db_username: document.getElementById('db_username').value,
        db_password: document.getElementById('db_password').value,
    };

    // Step 1: Test + save credentials via AJAX (this always works, even on Hostinger)
    fetch('{{ route("install.test_database") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(function(r) {
        if (!r.ok && r.status !== 200) {
            throw new Error('Server returned status ' + r.status);
        }
        return r.json();
    })
    .then(function(resp) {
        if (!resp.success) {
            result.style.display = 'block';
            result.className = 'alert alert-danger';
            result.textContent = resp.message || 'Database connection failed.';
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Save &amp; Continue &rarr;';
            return;
        }

        // Step 2: DB test passed and credentials saved to InstallerState.
        // Now try POST form submit. If that fails, use GET fallback.
        result.style.display = 'block';
        result.className = 'alert alert-success';
        result.textContent = 'Connection OK! Writing configuration...';

        // Use the GET fallback route which reads from InstallerState.
        // This avoids POST issues on Hostinger/LiteSpeed.
        window.location.href = '{{ route("install.save_environment") }}';
    })
    .catch(function(err) {
        result.style.display = 'block';
        result.className = 'alert alert-danger';
        result.textContent = 'Error: ' + (err.message || 'Could not reach the server.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save &amp; Continue &rarr;';
    });
});
</script>
@endsection
