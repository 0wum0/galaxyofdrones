@extends('admin.layout')

@section('title', 'Application Logs')

@section('content')
<div class="card">
    <h3>Laravel Log</h3>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <span style="font-size: 13px; color: #8b949e;">
            File size: {{ $logSizeFormatted }} &middot; Showing last {{ $lines }} lines
        </span>
        <div style="display: flex; gap: 8px;">
            <a href="{{ route('admin.logs.index', ['lines' => 50]) }}" class="btn btn-sm {{ $lines == 50 ? 'btn-primary' : 'btn-outline' }}">50</a>
            <a href="{{ route('admin.logs.index', ['lines' => 100]) }}" class="btn btn-sm {{ $lines == 100 ? 'btn-primary' : 'btn-outline' }}">100</a>
            <a href="{{ route('admin.logs.index', ['lines' => 500]) }}" class="btn btn-sm {{ $lines == 500 ? 'btn-primary' : 'btn-outline' }}">500</a>
        </div>
    </div>

    <div class="log-box">{{ $logContent ?: 'No log entries found.' }}</div>
</div>
@endsection
