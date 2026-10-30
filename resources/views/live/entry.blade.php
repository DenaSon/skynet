<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>DenaGate Live | پنل نمایندگی</title>
    <script src="https://telegram.org/js/telegram-web-app.js?64"></script>
    <style>
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; font: 15px/1.9 Tahoma, sans-serif; background: #0b1220; color: #f8fafc; }
        main { width: min(90vw, 440px); box-sizing: border-box; border: 1px solid #223346; border-radius: 24px; padding: 32px; background: #101c2c; text-align: center; }
        h1 { font-size: 24px; margin: 0 0 10px; } p { color: #b7c6d5; margin: 8px 0; }
        .brand { color: #14b8a6; font-weight: bold; letter-spacing: 1px; }
        .notice { padding: 12px; border-radius: 14px; margin-top: 20px; background: #172638; }
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
