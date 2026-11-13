@php
    $configuredBot = config('telegram.sales.bot_username');
    $bot = is_string($configuredBot) ? ltrim(trim($configuredBot), '@') : '';
    $botUrl = config('telegram.sales.enabled') === true
        && preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $bot) === 1
            ? 'https://t.me/'.$bot
            : null;
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#F6F8FA">
    <link rel="preload" href="/fonts/vazirmatn-variable.woff2" as="font" type="font/woff2" crossorigin>
    <title>DenaGate Live | پنل نمایندگی</title>
    <script src="https://telegram.org/js/telegram-web-app.js?64"></script>
    <style>
        @font-face { font-family: Vazirmatn; src: url('/fonts/vazirmatn-variable.woff2') format('woff2'); font-weight: 100 900; font-display: swap; }
        * { box-sizing: border-box; }
        body { display: flex; min-height: 100dvh; margin: 0; padding: max(20px, env(safe-area-inset-top)) 16px max(20px, env(safe-area-inset-bottom)); background: #F6F8FA; color: #1B2935; font: 15px/1.85 Vazirmatn, system-ui, sans-serif; }
        main { width: min(100%, 440px); margin: auto; padding: 28px 24px; border: 1px solid #E7ECEF; border-radius: 28px; background: #fff; text-align: center; box-shadow: 0 16px 48px rgba(14, 34, 44, .05); }
        .brand { display: flex; justify-content: center; align-items: center; gap: 10px; direction: ltr; color: #087F73; font-size: 18px; font-weight: 800; }
        .brand img { display: block; width: 48px; height: 48px; border-radius: 14px; object-fit: contain; }
        .brand small { margin-left: 3px; color: #687887; font-size: 12px; font-weight: 600; }
        h1 { margin: 22px 0 6px; font-size: 24px; line-height: 1.65; font-weight: 850; }
        .lead { margin: 0; font-size: 15px; font-weight: 700; }
        .description { margin: 12px 0 0; color: #536C76; line-height: 1.95; }
        .description strong { color: #087F73; }
        ul { display: grid; gap: 12px; margin: 22px 0 24px; padding: 0; list-style: none; text-align: right; }
        li { display: flex; gap: 10px; align-items: start; line-height: 1.75; font-size: 14px; }
        .check { flex: 0 0 24px; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: #EAF8F4; color: #087F73; font-size: 15px; font-weight: 800; }
        .actions { display: grid; gap: 10px; }
        .action { display: flex; min-height: 48px; justify-content: center; align-items: center; padding: 10px 16px; border: 1px solid transparent; border-radius: 14px; font-weight: 750; text-decoration: none; }
        .primary { background: #087F73; color: #fff; }
        .secondary { border-color: #D6E2E2; background: #F6F8FA; color: #1B2935; }
        .back { margin-top: 3px; color: #536C76; font-size: 14px; text-decoration: none; }
        .action:focus-visible, .back:focus-visible { outline: 3px solid #14B8A6; outline-offset: 3px; }
        .footnote { margin: 20px 0 0; color: #687887; font-size: 12px; line-height: 1.9; }
        @media (max-width: 360px) { main { padding: 22px 18px; } h1 { font-size: 22px; } }
    </style>
</head>
<body>
<main aria-labelledby="intro-heading">
    <div class="brand">
        <img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="48" height="48">
        <span>DenaGate <small>Live</small></span>
    </div>
    <h1 id="intro-heading">پنل نمایندگی دناگیت 🚀</h1>
    <p class="lead">هنوز نماینده دناگیت نیستید؟</p>
    <p class="description">سرویس‌های اختصاصی بسازید، مشتری‌هایتان را مدیریت کنید و از <strong>۳۵٪ تخفیف ویژه در پلن‌های مشمول</strong> بهره‌مند شوید.</p>
    <ul aria-label="امکانات نمایندگی">
        <li><span class="check" aria-hidden="true">✓</span><span>ساخت سرویس با حجم و مدت دلخواه</span></li>
        <li><span class="check" aria-hidden="true">✓</span><span>مدیریت سرویس‌ها و مشتریان</span></li>
        <li><span class="check" aria-hidden="true">✓</span><span>پنل اختصاصی نمایندگان در تلگرام</span></li>
    </ul>
    <nav class="actions" aria-label="مسیرهای نمایندگی">
        @if ($botUrl !== null)
            <a class="action primary" href="{{ $botUrl }}?start=demo_panel" target="_blank" rel="noopener noreferrer" data-telegram-link>🚀 درخواست نمایندگی</a>
        @endif
        <a class="action secondary" href="{{ route('denagate-live.demo.entry') }}">👀 مشاهده دمو پنل</a>
        @if ($botUrl !== null)
            <a class="back" href="{{ $botUrl }}" target="_blank" rel="noopener noreferrer" data-telegram-link>بازگشت به ربات دناگیت</a>
        @else
            <p class="footnote">برای درخواست نمایندگی از بات فروش دناگیت اقدام کنید.</p>
        @endif
    </nav>
    <p class="footnote">فعال‌سازی نمایندگی پس از بررسی و تأیید مدیریت انجام می‌شود.</p>
</main>
<script>
    document.querySelectorAll('[data-telegram-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const app = window.Telegram?.WebApp;
            if (app && app.platform !== 'unknown' && typeof app.openTelegramLink === 'function') {
                event.preventDefault();
                app.openTelegramLink(link.href);
            }
        });
    });
</script>
</body>
</html>
