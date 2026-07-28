<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="api-base" content="{{ url('/api') }}">

    {{-- Reverb (Laravel WebSockets) configuration – consumed by resources/js/stores/realtime.js --}}
    <meta name="reverb-key"     content="{{ env('REVERB_APP_KEY', '') }}">
    <meta name="reverb-host"    content="{{ env('REVERB_HOST', '') }}">
    <meta name="reverb-port"    content="{{ env('REVERB_PORT', 8080) }}">
    <meta name="reverb-scheme"  content="{{ env('REVERB_SCHEME', 'http') }}">
    <meta name="reverb-cluster" content="{{ env('REVERB_CLUSTER', 'mt1') }}">

    <title>{{ config('app.name', 'Xerex Panel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-100 antialiased">
    <div id="app"></div>
</body>
</html>
