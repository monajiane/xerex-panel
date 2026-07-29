<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Xerex Panel Installer' }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><text y='20' font-size='20'>⚙️</text></svg>">
    <style>
        :root {
            --bg:        #0b1020;
            --bg-2:      #111733;
            --card:      #161c3a;
            --border:    #25305a;
            --text:      #e6e9f5;
            --muted:     #8893b8;
            --primary:   #6366f1;
            --primary-2: #4f46e5;
            --green:     #10b981;
            --red:       #ef4444;
            --yellow:    #f59e0b;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background:
                radial-gradient(1200px 600px at 20% -10%, rgba(99,102,241,.25), transparent 60%),
                radial-gradient(800px 400px at 110% 110%, rgba(16,185,129,.18), transparent 60%),
                var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            min-height: 100vh;
        }
        .wrap { max-width: 880px; margin: 0 auto; padding: 32px 20px 80px; }
        .header {
            display: flex; align-items: center; gap: 16px;
            margin-bottom: 28px;
        }
        .header .logo {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, #6366f1, #10b981);
            display: grid; place-items: center;
            font-weight: 800; font-size: 22px; color: #fff;
        }
        .header h1 { font-size: 22px; margin: 0; font-weight: 700; }
        .header .sub { color: var(--muted); font-size: 13px; margin-top: 2px; }

        .steps {
            display: flex; gap: 8px; flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .step {
            flex: 1 1 0; min-width: 130px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            color: var(--muted);
        }
        .step .n {
            display: inline-block; width: 22px; height: 22px;
            border-radius: 50%; background: var(--bg-2);
            text-align: center; line-height: 22px;
            font-weight: 700; font-size: 12px;
            margin-right: 8px; color: var(--muted);
        }
        .step.active { border-color: var(--primary); color: var(--text); }
        .step.active .n { background: var(--primary); color: #fff; }
        .step.done { color: var(--green); border-color: rgba(16,185,129,.4); }
        .step.done .n { background: var(--green); color: #04221b; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 28px;
        }
        .card h2 { margin: 0 0 6px; font-size: 20px; }
        .card .lead { color: var(--muted); margin: 0 0 20px; font-size: 14px; }

        label { display: block; font-size: 13px; color: var(--muted); margin: 14px 0 6px; }
        input[type=text], input[type=email], input[type=url], input[type=password], input[type=number], select {
            width: 100%;
            background: var(--bg-2);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 13px;
            font-size: 14px;
            outline: none;
            transition: border-color .15s;
        }
        input:focus, select:focus { border-color: var(--primary); }
        .row { display: grid; gap: 14px; grid-template-columns: 2fr 1fr; }
        @media (max-width: 540px) { .row { grid-template-columns: 1fr; } }

        .btn {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary), var(--primary-2));
            color: #fff;
            border: 0;
            border-radius: 10px;
            padding: 12px 22px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn.secondary {
            background: var(--bg-2);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .actions {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 24px; gap: 12px;
        }

        .check {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; font-size: 14px;
            border-bottom: 1px dashed rgba(255,255,255,.05);
        }
        .check:last-child { border-bottom: 0; }
        .check .dot {
            width: 22px; height: 22px; border-radius: 50%;
            display: grid; place-items: center; font-size: 12px; font-weight: 700;
            flex-shrink: 0;
        }
        .check.ok .dot { background: rgba(16,185,129,.15); color: var(--green); }
        .check.fail .dot { background: rgba(239,68,68,.15); color: var(--red); }
        .check .detail { margin-left: auto; color: var(--muted); font-size: 12px; }

        .alert {
            border-radius: 10px; padding: 12px 14px; font-size: 14px;
            margin-top: 14px;
        }
        .alert.error { background: rgba(239,68,68,.1); color: #fecaca; border: 1px solid rgba(239,68,68,.3); }
        .alert.success { background: rgba(16,185,129,.1); color: #bbf7d0; border: 1px solid rgba(16,185,129,.3); }
        .alert.info { background: rgba(99,102,241,.08); color: #c7d2fe; border: 1px solid rgba(99,102,241,.25); }

        ul.bullets { color: var(--muted); line-height: 1.7; font-size: 14px; padding-left: 18px; }
        code { background: var(--bg-2); padding: 2px 6px; border-radius: 4px; font-size: 12px; }

        .terminal {
            background: #0a0e1f;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            color: #c7d2fe;
            max-height: 280px; overflow: auto;
            white-space: pre-wrap;
        }
        .terminal .ok { color: var(--green); }
        .terminal .fail { color: var(--red); }
        .terminal .label { color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div class="logo">X</div>
            <div>
                <h1>Xerex Panel — Installer</h1>
                <div class="sub">Self-hosted edge proxy &amp; CDN control plane</div>
            </div>
        </div>

        <div class="steps">
            <div class="step {{ $step === 1 ? 'active' : 'done' }}"><span class="n">1</span> Requirements</div>
            <div class="step {{ $step === 2 ? 'active' : ($step > 2 ? 'done' : '') }}"><span class="n">2</span> Database</div>
            <div class="step {{ $step === 3 ? 'active' : ($step > 3 ? 'done' : '') }}"><span class="n">3</span> Admin</div>
            <div class="step {{ $step === 4 ? 'active' : ($step > 4 ? 'done' : '') }}"><span class="n">4</span> Install</div>
            <div class="step {{ $step === 5 ? 'active' : '' }}"><span class="n">5</span> Done</div>
        </div>

        <div class="card">
            @yield('content')
        </div>
    </div>
</body>
</html>
