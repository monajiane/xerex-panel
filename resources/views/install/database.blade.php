@extends('install.layout', ['step' => 2, 'title' => 'Xerex Panel — Database'])

@section('content')
    <h2>Database connection</h2>
    <p class="lead">
        Choose the database driver and provide credentials. The wizard will probe the
        connection before saving anything to <code>.env</code>.
    </p>

    @if ($errors->any())
        <div class="alert error">
            <strong>Could not connect.</strong>
            <ul style="margin:6px 0 0 18px;">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.database.store') }}">
        @csrf

        <label for="driver">Driver</label>
        <select name="driver" id="driver" required>
            <option value="mysql"  @selected($defaults['driver'] === 'mysql')>MySQL / MariaDB</option>
            <option value="pgsql"  @selected($defaults['driver'] === 'pgsql')>PostgreSQL</option>
            <option value="sqlite" @selected($defaults['driver'] === 'sqlite')>SQLite (file)</option>
        </select>

        <div id="tcp-fields">
            <div class="row">
                <div>
                    <label for="host">Host</label>
                    <input type="text" name="host" id="host" value="{{ old('host', $defaults['host'])">
                </div>
                <div>
                    <label for="port">Port</label>
                    <input type="number" name="port" id="port" value="{{ old('port', $defaults['port']) }}">
                </div>
            </div>

            <label for="user">Username</label>
            <input type="text" name="user" id="user" value="{{ old('user', $defaults['user'])">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" value="{{ old('password') }}">
        </div>

        <div id="sqlite-name" style="display:none;">
            <label for="name-sqlite">SQLite file path</label>
            <input type="text" name="name" id="name-sqlite" placeholder="{{ database_path('database.sqlite') }}" value="{{ old('name', database_path('database.sqlite')) }}">
            <div class="alert info" style="margin-top:8px;">
                The directory must be writable by the PHP process. The file will be created
                automatically on the next step.
            </div>
        </div>

        <label for="name" id="name-label">Database name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $defaults['name']) }}">

        <div class="actions">
            <a href="{{ route('install.welcome') }}" class="btn secondary">← Back</a>
            <button type="submit" class="btn">Test &amp; continue →</button>
        </div>
    </form>

    <script>
        const driver = document.getElementById('driver');
        const tcp    = document.getElementById('tcp-fields');
        const sqName = document.getElementById('sqlite-name');
        const name   = document.getElementById('name');
        const nameLbl = document.getElementById('name-label');
        const nameSq = document.getElementById('name-sqlite');

        function refresh() {
            const d = driver.value;
            const isSqlite = d === 'sqlite';
            tcp.style.display    = isSqlite ? 'none' : '';
            sqName.style.display = isSqlite ? '' : 'none';
            name.style.display   = isSqlite ? 'none' : '';
            nameLbl.style.display = isSqlite ? 'none' : '';

            if (isSqlite) {
                // The SQLite form has its own input; sync the value.
                nameSq.addEventListener('input', () => name.value = nameSq.value);
            }
        }
        driver.addEventListener('change', refresh);
        refresh();
    </script>
@endsection
