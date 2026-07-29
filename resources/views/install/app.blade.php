@extends('install.layout', ['step' => 3, 'title' => 'Xerex Panel — Admin & URL'])

@section('content')
    <h2>Public URL &amp; first admin</h2>
    <p class="lead">
        The public URL is used for absolute links, OAuth callbacks, and email signatures.
        The admin user is created after migrations finish on the next step.
    </p>

    @if ($errors->any())
        <div class="alert error">
            <ul style="margin:0 0 0 18px;">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.app.store') }}">
        @csrf

        <div class="row">
            <div>
                <label for="app_url">Public URL</label>
                <input type="url" name="app_url" id="app_url" value="{{ old('app_url', $defaults['app_url']) }}" placeholder="https://panel.example.com" required>
            </div>
            <div>
                <label for="app_env">Environment</label>
                <select name="app_env" id="app_env" required>
                    <option value="local"      @selected(old('app_env', $defaults['app_env']) === 'local')>Local</option>
                    <option value="staging"    @selected(old('app_env', $defaults['app_env']) === 'staging')>Staging</option>
                    <option value="production" @selected(old('app_env', $defaults['app_env']) === 'production')>Production</option>
                </select>
            </div>
        </div>

        <h3 style="font-size:14px; margin:24px 0 4px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">First admin user</h3>

        <label for="admin_name">Name</label>
        <input type="text" name="admin_name" id="admin_name" value="{{ old('admin_name', $defaults['admin_name']) }}" required>

        <label for="admin_email">Email</label>
        <input type="email" name="admin_email" id="admin_email" value="{{ old('admin_email', $defaults['admin_email']) }}" required>

        <div class="row">
            <div>
                <label for="admin_password">Password</label>
                <input type="password" name="admin_password" id="admin_password" minlength="8" required>
            </div>
            <div>
                <label for="admin_password_confirmation">Confirm</label>
                <input type="password" name="admin_password_confirmation" id="admin_password_confirmation" minlength="8" required>
            </div>
        </div>

        <div class="alert info" style="margin-top:18px;">
            <strong>Heads-up:</strong> write this password down or use a password manager.
            It is the only way in until you set up SSO or create another admin from the panel UI.
        </div>

        <div class="actions">
            <a href="{{ route('install.database') }}" class="btn secondary">← Back</a>
            <button type="submit" class="btn">Continue →</button>
        </div>
    </form>
@endsection
