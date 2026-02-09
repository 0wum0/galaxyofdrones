@extends('install.layout', ['step' => 2])

@section('content')
<div class="card">
    <h2>Step 2: Database Configuration</h2>
    <p style="color: #78909c; margin-bottom: 20px;">
        Enter your MySQL database credentials. You can find these in your Hostinger hPanel under Databases.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('install.environment') }}" id="dbForm">
        @csrf

        <div class="form-group">
            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host" value="{{ old('db_host', trimQuotes($defaults['db_host'] ?? 'localhost')) }}" required>
        </div>

        <div class="form-group">
            <label for="db_port">Database Port</label>
            <input type="number" id="db_port" name="db_port" value="{{ old('db_port', trimQuotes($defaults['db_port'] ?? '3306')) }}" required>
        </div>

        <div class="form-group">
            <label for="db_database">Database Name</label>
            <input type="text" id="db_database" name="db_database" value="{{ old('db_database', trimQuotes($defaults['db_database'] ?? '')) }}" required placeholder="e.g. u123456789_galaxy">
        </div>

        <div class="form-group">
            <label for="db_username">Database Username</label>
            <input type="text" id="db_username" name="db_username" value="{{ old('db_username', trimQuotes($defaults['db_username'] ?? '')) }}" required placeholder="e.g. u123456789_admin">
        </div>

        <div class="form-group">
            <label for="db_password">Database Password</label>
            <input type="password" id="db_password" name="db_password" value="{{ old('db_password', trimQuotes($defaults['db_password'] ?? '')) }}" placeholder="Your database password">
        </div>

        <div id="testResult" style="display:none;" class="alert"></div>

        <div class="actions">
            <button type="button" class="btn btn-secondary" id="testBtn" onclick="testConnection()">
                Test Connection
            </button>
            <button type="submit" class="btn btn-primary" id="submitBtn">
                Next: Install &rarr;
            </button>
        </div>
    </form>
</div>

<script>
function testConnection() {
    const btn = document.getElementById('testBtn');
    const result = document.getElementById('testResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Testing...';
    result.style.display = 'none';

    const data = {
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
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(r => r.json())
    .then(data => {
        result.style.display = 'block';
        result.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
        result.textContent = data.message;
    })
    .catch(e => {
        result.style.display = 'block';
        result.className = 'alert alert-danger';
        result.textContent = 'Connection test failed. Please check your details.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
    });
}
</script>
@endsection
