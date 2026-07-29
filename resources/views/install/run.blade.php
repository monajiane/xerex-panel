@extends('install.layout', ['step' => 4, 'title' => 'Xerex Panel — Run installer'])

@section('content')
    <h2>Ready to install</h2>
    <p class="lead">
        Click <strong>Run installer</strong> to apply migrations, seed default data
        (roles, plans, WAF presets, rate-limit policies), and create your admin user.
    </p>

    <ul class="bullets">
        <li>Migrations: <strong>~24 schema files</strong> will be applied to the database.</li>
        <li>Seeders: roles &amp; permissions, default plans, WAF rules, rate-limit presets.</li>
        <li>Admin user <code>{{ $admin_email }}</code> will be created (or updated if it already exists).</li>
        <li>Lock file <code>storage/installed.lock</code> is written on success.</li>
    </ul>

    <div class="alert info" style="margin-top:18px;">
        Estimated time: <strong>5–30 seconds</strong> on a typical VPS.
    </div>

    @if ($errors->any())
        <div class="alert error" style="margin-top:18px;">
            <strong>The previous attempt failed.</strong>
            <ul style="margin:6px 0 0 18px;">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.run.store') }}" id="run-form">
        @csrf
        <div class="actions">
            <a href="{{ route('install.app') }}" class="btn secondary">← Back</a>
            <button type="submit" class="btn" id="run-btn">Run installer →</button>
        </div>
    </form>

    <script>
        const f = document.getElementById('run-form');
        const b = document.getElementById('run-btn');
        f.addEventListener('submit', () => {
            b.disabled = true;
            b.textContent = 'Running…';
        });
    </script>
@endsection
