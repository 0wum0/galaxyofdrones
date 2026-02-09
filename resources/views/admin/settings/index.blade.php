@extends('admin.layout')

@section('title', 'Game Settings')

@section('content')
<div class="card">
    <h3>Add New Setting</h3>
    <form method="POST" action="{{ route('admin.settings.store') }}">
        @csrf
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: end;">
            <div class="form-group">
                <label for="key">Key</label>
                <input type="text" id="key" name="key" required placeholder="e.g. game.speed">
            </div>
            <div class="form-group">
                <label for="value">Value</label>
                <input type="text" id="value" name="value" placeholder="e.g. 1.5">
            </div>
            <div class="form-group">
                <label for="group">Group</label>
                <input type="text" id="group" name="group" value="general" placeholder="e.g. general">
            </div>
            <div class="form-group">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="string">String</option>
                    <option value="integer">Integer</option>
                    <option value="float">Float</option>
                    <option value="boolean">Boolean</option>
                    <option value="json">JSON</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="description">Description (optional)</label>
            <input type="text" id="description" name="description" placeholder="What this setting controls...">
        </div>
        @if ($errors->any())
            <div class="alert alert-danger">
                @foreach ($errors->all() as $error) {{ $error }}<br> @endforeach
            </div>
        @endif
        <button type="submit" class="btn btn-primary">Add Setting</button>
    </form>
</div>

@forelse ($groups as $group => $groupSettings)
<div class="card">
    <h3>{{ ucfirst($group) }}</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Value</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($groupSettings as $setting)
                <tr>
                    <td><code>{{ $setting->key }}</code></td>
                    <td>
                        <form method="POST" action="{{ route('admin.settings.update', $setting) }}" class="form-inline">
                            @csrf
                            @method('PUT')
                            <input type="text" name="value" value="{{ $setting->value }}" style="width: 200px; padding: 4px 8px; font-size: 13px;">
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </td>
                    <td><span class="badge badge-blue">{{ $setting->type }}</span></td>
                    <td style="font-size: 12px; color: #8b949e;">{{ $setting->description }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.settings.destroy', $setting) }}" style="display: inline;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this setting?')">Del</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@empty
<div class="card">
    <p style="text-align: center; color: #484f58;">No game settings configured yet. Add one above.</p>
</div>
@endforelse
@endsection
