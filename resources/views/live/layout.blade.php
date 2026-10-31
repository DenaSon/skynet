<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F6F8FA">
    <meta name="robots" content="noindex, nofollow">
    <title>DenaGate Live | پنل نمایندگی</title>
    <link rel="preload" href="/fonts/vazirmatn-variable.woff2" as="font" type="font/woff2" crossorigin>
    <script src="https://telegram.org/js/telegram-web-app.js?64"></script>
    @vite(['resources/css/app.css', 'resources/js/live.js'])
    <style>[x-cloak] { display: none !important; }</style>
    @livewireStyles
</head>
<body class="denagate-live min-h-dvh bg-[#F6F8FA] text-[#1B2935] antialiased">
    {{ $slot }}
    @livewireScriptConfig
</body>
</html>
