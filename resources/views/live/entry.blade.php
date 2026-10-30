<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#F6F8FA">
    <link rel="preload" href="/fonts/vazirmatn-variable.woff2" as="font" type="font/woff2" crossorigin>
    <title>DenaGate Live | پنل نمایندگی</title>
    <script src="https://telegram.org/js/telegram-web-app.js?64"></script>
    <style>
        @font-face { font-family: Vazirmatn; src: url('/fonts/vazirmatn-variable.woff2') format('woff2'); font-weight: 100 900; font-display: swap; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; font: 15px/1.9 Vazirmatn, system-ui, sans-serif; background: #F6F8FA; color: #1B2935; }
        main { width: min(90vw, 440px); box-sizing: border-box; border: 1px solid #E7ECEF; border-radius: 28px; padding: 32px; background: #fff; text-align: center; box-shadow: 0 16px 48px rgba(14,34,44,.05); }
        h1 { font-size: 24px; margin: 0 0 10px; } p { color: #687887; margin: 8px 0; }
        .brand { color: #087F73; font-weight: 800; letter-spacing: .2px; }
        .notice { padding: 12px; border-radius: 14px; margin-top: 20px; background: #EAF8F4; color: #087F73; }
    </style>
</head>
<body>
<main>
    <div class="brand">DenaGate Live</div>
    <h1>پنل نمایندگی</h1>
    <p>ورود امن از طریق تلگرام</p>
    <p class="notice" id="status" role="status">در حال بررسی ورود…</p>
    <form id="telegram-login" method="POST" action="{{ route('denagate-live.telegram.login') }}">
        @csrf
        <input name="init_data" type="hidden" value="">
    </form>
</main>
<script>
    (() => {
        const app = window.Telegram?.WebApp;
        const status = document.getElementById('status');
        if (!app?.initData) {
            status.textContent = 'برای ورود، پنل را از بات فروش دناگیت باز کنید.';
            return;
        }
        app.ready();
        const form = document.getElementById('telegram-login');
        form.elements.init_data.value = app.initData;
        form.requestSubmit();
    })();
</script>
</body>
</html>
