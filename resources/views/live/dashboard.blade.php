<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>DenaGate Live | داشبورد</title>
    <style>
        body { margin: 0; min-height: 100dvh; background: #0b1220; color: #f8fafc; font: 15px/1.9 Tahoma, sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 24px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        h1 { font-size: 24px; } .brand { color: #14b8a6; font-weight: bold; }
        article { background: #101c2c; border: 1px solid #223346; border-radius: 22px; padding: 28px; margin-top: 24px; }
        p { color: #b7c6d5; } button { padding: 10px 16px; border: 1px solid #294359; border-radius: 12px; background: #172638; color: #f8fafc; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <header>
        <span class="brand">DenaGate Live</span>
        <form method="POST" action="{{ route('denagate-live.logout') }}">@csrf<button type="submit">خروج</button></form>
    </header>
    <article>
        <h1>خوش آمدید، {{ $name }}</h1>
        <p>پنل نمایندگی شما با موفقیت و به‌صورت امن به حساب تلگرام متصل شد.</p>
        <p>بخش‌های اعتبار، سرویس‌ها و گزارش فروش در مرحله بعد از منطق فعلی دناگیت نمایش داده می‌شوند.</p>
    </article>
</main>
</body>
</html>
