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
                background-color: #f8fafc;
            }

            html.dark {
                background-color: #0b1220;
            }
            #app-startup {
                position: fixed;
                inset: 0;
                z-index: 1000;
                display: grid;
                place-content: center;
                justify-items: center;
                gap: 24px;
                padding: max(24px, env(safe-area-inset-top)) max(24px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(24px, env(safe-area-inset-left));
                background: #f8fafc;
                color: #64748b;
                text-align: center;
                font: 14px/1.5 ui-sans-serif, system-ui, sans-serif;
            }
            html.dark #app-startup { background: #0b1220; color: #94a3b8; }
            .startup-status { display: flex; align-items: center; gap: 8px; }
            .startup-spinner {
                width: 16px;
                height: 16px;
                border: 2px solid currentColor;
                border-right-color: transparent;
                border-radius: 50%;
            }
            #app-startup-retry {
                min-height: 44px;
                padding: 8px 16px;
                border: 1px solid currentColor;
                border-radius: 8px;
                background: transparent;
                color: inherit;
                font: inherit;
                cursor: pointer;
            }
            @media (prefers-reduced-motion: no-preference) {
                .startup-spinner { animation: startup-spin 1s linear infinite; }
            }
            @keyframes startup-spin { to { transform: rotate(360deg); } }
            .startup-brand { position: relative; display: block; width: 180px; max-width: 80vw; line-height: 0; }
            .startup-brand img { display: block; width: 100%; height: auto; }
            .startup-brand-light { display: none !important; position: absolute; inset: 0; filter: brightness(0) invert(1); }
            html.dark .startup-brand-light { display: block !important; }
            .startup-brand-start { clip-path: inset(0 38% 0 36%); }
            .startup-brand-end { clip-path: inset(0 0 0 84%); }
        </style>

        <link rel="icon" href="/favicon.ico?v=chait-1" sizes="16x16 32x32">
        <link rel="icon" href="/favicon-16x16.png?v=chait-1" type="image/png" sizes="16x16">
        <link rel="icon" href="/favicon-32x32.png?v=chait-1" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/brand/app-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <div id="app-startup" role="status" aria-live="polite" aria-label="Carregando aplicativo">
            <span class="startup-brand" role="img" aria-label="chAIt">
                <img src="/brand/logo.png" alt="">
                <img class="startup-brand-light startup-brand-start" src="/brand/logo.png" alt="" aria-hidden="true">
                <img class="startup-brand-light startup-brand-end" src="/brand/logo.png" alt="" aria-hidden="true">
            </span>
            <span class="startup-status"><span class="startup-spinner" aria-hidden="true"></span>Carregando…</span>
            <button id="app-startup-retry" type="button" hidden onclick="location.reload()">Recarregar</button>
        </div>
        <script>setTimeout(function(){var b=document.getElementById('app-startup-retry');if(b)b.hidden=false},10000);</script>
        <x-inertia::app />
    </body>
</html>
