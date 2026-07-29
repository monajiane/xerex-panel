@extends('install.layout', ['step' => 5, 'title' => 'Xerex Panel — Installation complete'])

@section('content')
    <h2>🎉 Installation complete</h2>
    <p class="lead">
        Your Xerex Panel is installed. To prevent accidental re-installs, the
        <code>storage/installed.lock</code> file was created.
    </p>

    <div class="alert success">
        Installed at: <strong>{{ $install['installed_at'] ?? now()->toIso8601String() }}</strong><br>
        PHP version:  <strong>{{ $install['php'] ?? PHP_VERSION }}</strong><br>
        Admin user:   <strong>{{ $install['admin_email'] ?? '(see /admin/users)' }}</strong>
    </div>

    <h3 style="font-size:15px; margin:18px 0 6px;">Next steps</h3>
    <ul class="bullets">
        <li>Build the front-end bundle: <code>npm install &amp;&amp; npm run build</code></li>
        <li>Link the storage directory: <code>php artisan storage:link</code></li>
        <li>Start the queue worker + scheduler:
            <pre><code>php artisan horizon          # or: php artisan queue:work
php artisan schedule:work     # in a separate terminal</code></pre>
        </li>
        <li>(Production) Put a reverse proxy in front:
            <pre><code>server {
  server_name {{ parse_url($app_url, PHP_URL_HOST) ?? 'panel.example.com' }};
  client_max_body_size 100m;
  location / { proxy_pass http://127.0.0.1:8000; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto $scheme; }
}</code></pre>
        </li>
        <li>Visit <a href="{{ url('/') }}" style="color: #c7d2fe;">{{ $app_url }}</a> and log in with the admin account you just created.</li>
    </ul>

    <div class="actions">
        <a href="{{ url('/') }}" class="btn">Open the panel →</a>
    </div>
@endsection
