@php
    [$heading, $message] = match ($statusCode) {
        401, 419 => ['زمان ورود شما به پایان رسیده است', 'پنل را ببندید و از دکمه «اپلیکیشن» در بات فروش دوباره وارد شوید. پیش از تکرار پرداخت، وضعیت سفارش و اعتبارتان را بررسی کنید.'],
        403 => ['ورود به پنل امکان‌پذیر نیست', 'پنل را دوباره از بات فروش باز کنید. اگر مشکل ادامه داشت، دسترسی نمایندگی خود را از پشتیبانی پیگیری کنید.'],
        429 => ['لطفاً کمی صبر کنید', 'درخواست‌های ورود زیادی ثبت شده است. کمی بعد پنل را از بات فروش دوباره باز کنید.'],
        503 => ['ورود موقتاً در دسترس نیست', 'لطفاً کمی بعد دوباره از بات فروش وارد شوید.'],
        default => ['اطلاعات ورود کامل نیست', 'پنل را ببندید و دوباره از دکمه «اپلیکیشن» در بات فروش باز کنید.'],
    };
    if (request()->is('demo', 'demo/*')) {
        [$heading, $message] = match ($statusCode) {
            401, 419 => ['زمان ورود شما به پایان رسیده است', 'پیش‌نمایش را ببندید و از بات فروش دوباره باز کنید.'],
            403 => ['ورود به پیش‌نمایش امکان‌پذیر نیست', 'برای ورود، حساب فعال خود را از بات فروش دناگیت بررسی کنید.'],
            429 => ['لطفاً کمی صبر کنید', 'درخواست‌های زیادی ثبت شده است. کمی بعد دوباره تلاش کنید.'],
            503 => ['پیش‌نمایش موقتاً در دسترس نیست', 'کمی بعد از بات فروش دوباره وارد شوید.'],
            default => ['اطلاعات ورود کامل نیست', 'پیش‌نمایش را از بات فروش دوباره باز کنید.'],
        };
    }
    $configuredBot = config('telegram.sales.bot_username');
    $bot = is_string($configuredBot) ? ltrim(trim($configuredBot), '@') : '';
    $botUrl = config('telegram.sales.enabled') === true && preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $bot) === 1 ? 'https://t.me/'.$bot : null;
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>DenaGate | ورود به پنل</title>
    <style>
        @font-face { font-family: Vazirmatn; src: url('/fonts/vazirmatn-variable.woff2') format('woff2'); font-weight: 100 900; font-display: swap; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; font: 15px/1.9 Vazirmatn, system-ui, sans-serif; background: #F6F8FA; color: #1B2935; }
        main { width: min(90vw, 440px); box-sizing: border-box; border: 1px solid #E7ECEF; border-radius: 28px; padding: 32px; background: #fff; text-align: center; box-shadow: 0 16px 48px rgba(14,34,44,.05); }
        h1 { font-size: 24px; margin: 0 0 10px; } p { color: #687887; margin: 8px 0; }
        .brand { display: flex; align-items: center; justify-content: center; gap: 10px; color: #087F73; font-weight: 800; letter-spacing: .2px; direction: ltr; }
        .brand img { display: block; width: 48px; height: 48px; border-radius: 14px; object-fit: contain; }
        .notice { padding: 12px; border-radius: 14px; margin-top: 20px; background: #EAF8F4; color: #087F73; }
    </style>
</head>
<body>
<main>
    <div class="brand"><img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="48" height="48"> <span>DenaGate</span></div>
    <h1>{{ $heading }}</h1>
    <p role="alert">{{ $message }}</p>
    @if ($botUrl !== null)
        <a href="{{ $botUrl }}" rel="noopener noreferrer" style="display: inline-block; margin-top: 20px; padding: 12px 20px; background: #087F73; color: #fff; border-radius: 12px; text-decoration: none;">بازگشت به بات فروش</a>
    @endif
</main>
</body>
</html>
