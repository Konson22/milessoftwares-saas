<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Follow the system colour scheme before the first paint. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>

        <style>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        {{-- Logbook's own icons, served under its path (public/logbook/images). --}}
        <link rel="icon" href="/logbook/images/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/logbook/images/favicon-96x96.png" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/logbook/images/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
