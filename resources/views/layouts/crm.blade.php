<!doctype html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F6F8FA">
    <meta name="robots" content="noindex, nofollow">
    <title>DenaGate | مدیریت مشتریان</title>
    <link rel="preload" href="{{ asset('fonts/vazirmatn-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/crm.css', 'resources/js/crm.js'])
    @livewireStyles
</head>
<body class="denagate-crm min-h-dvh bg-base-200 text-base-content antialiased">
    {{ $slot }}
    @livewireScriptConfig
</body>
</html>
