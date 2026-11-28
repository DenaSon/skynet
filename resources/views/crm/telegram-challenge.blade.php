<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>تأیید هویت — DenaGate</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f5f7fa; color: #172033; font-family: Tahoma, sans-serif; }
        main { width: min(100%, 420px); padding: 32px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 12px 32px rgb(15 23 42 / 6%); }
        h1 { margin: 0 0 12px; font-size: 20px; }
        p { color: #64748b; line-height: 1.9; }
        .error { margin: 16px 0; color: #b91c1c; }
        button { width: 100%; margin-top: 12px; padding: 12px 16px; border: 0; border-radius: 9px; background: #0f766e; color: #fff; font: inherit; cursor: pointer; }
        button.secondary { background: #e2e8f0; color: #334155; }
    </style>
</head>
<body>
<main>
    <h1>تأیید هویت تلگرام</h1>
    <p>برای ورود به پنل مدیریت، حساب تلگرام متصل به حساب مدیریتی خود را تأیید کنید.</p>

    @if (session('telegram_auth_error'))
        <p class="error" role="alert">{{ session('telegram_auth_error') }}</p>
    @endif

    <form method="post" action="{{ route('crm.telegram.start') }}">
        @csrf
        <button type="submit">ادامه با تلگرام</button>
    </form>

    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button class="secondary" type="submit">خروج</button>
    </form>
</main>
</body>
</html>
