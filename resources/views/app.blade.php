<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const media = window.matchMedia('(prefers-color-scheme: dark)');
                    const prefersDark = media.matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }

                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #fafafa;
            }

            html.dark {
                background-color: #09090b;
            }
        </style>

        <link rel="icon" href="/brand/symbol.png" type="image/png">
        <link rel="apple-touch-icon" href="/brand/app-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <div id="app-startup" role="status" style="position:fixed;inset:0;display:grid;place-content:center;text-align:center;background:inherit;z-index:1000">
            <img src="/brand/logo.png" alt="chAIt" style="width:180px;max-width:80vw;background:white;border-radius:8px"><span>Carregando…</span>
            <button id="app-startup-retry" type="button" hidden onclick="location.reload()">Recarregar</button>
        </div>
        <script>setTimeout(function(){var b=document.getElementById('app-startup-retry');if(b)b.hidden=false},10000);</script>
        <x-inertia::app />
    </body>
</html>
