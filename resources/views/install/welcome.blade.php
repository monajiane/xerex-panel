@extends('install.layout', ['step' => 1, 'title' => 'Xerex Panel — Welcome'])

@section('content')
    <h2>Welcome</h2>
    <p class="lead">
        This wizard will configure your <strong>Xerex Panel</strong> in four short steps.
        Before we start we need to verify that your server meets the requirements.
    </p>

    <h3 style="font-size:15px; margin:18px 0 6px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Server check</h3>
    @foreach ($checks as $c)
        <div class="check {{ $c['ok'] ? 'ok' : 'fail' }}">
            <div class="dot">{{ $c['ok'] ? '✓' : '✕' }}</div>
            <div>{!! $c['label'] !!}</div>
            <div class="detail">{{ $c['detail'] }}</div>
        </div>
    @endforeach

    @if (! $passes)
        <div class="alert error">
            Some requirements are not met. Please address the failing items and reload this page.
            On Debian/Ubuntu the most common fix is:
            <pre style="margin-top:8px;"><code>sudo apt install php-cli php-mbstring php-xml php-bcmath php-curl php-zip php-pgsql php-intl php-gd unzip git</code></pre>
        </div>
    @else
        <div class="alert success">All requirements satisfied. You can proceed to the next step.</div>
    @endif

    <div class="actions">
        <span class="sub" style="color:var(--muted); font-size:13px;">Tip: you can also run this from the terminal: <code>php artisan xerex:install</code></span>
        @if ($passes)
            <a href="{{ route('install.database') }}" class="btn">Continue →</a>
        @else
            <button class="btn" disabled>Continue →</button>
        @endif
    </div>
@endsection
