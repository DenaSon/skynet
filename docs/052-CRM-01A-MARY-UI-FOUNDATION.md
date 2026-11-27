# CRM-01A — فعال‌سازی کنترل‌شده Mary UI و پوسته امن CRM

**وضعیت:** برنامه اجرایی مستند شده؛ شروع کدنویسی نیازمند تأیید صریح است
**تاریخ:** ۱۴۰۵/۰۹/۰۶ — ۲۰۲۶-۱۱-۲۷
**شاخه مبنا:** `master`
**دامنه:** فقط پایه رابط وب و دسترسی Super Admin؛ بدون داده تجاری

---

## ۱. هدف

فعال‌سازی کنترل‌شده Mary UI در برنامه Laravel فعلی و ایجاد پوسته مستقل وب برای CRM، با استفاده از Livewire 4 و بدون اثر جانبی روی DenaGate Live.

خروجی این مرحله فقط یک صفحه پوسته/آزمایشی است که Super Admin مورد اعتماد می‌تواند باز کند. هیچ فهرست مشتری، نماینده، سفارش، گزارش فروش یا اطلاعات مالی در این مرحله خوانده یا نمایش داده نمی‌شود.

## ۲. مبنای فنی تأییدشده

| فناوری | وضعیت روی master |
|---|---|
| Laravel | 13.35.0 در Composer Lock |
| Livewire | 4.4.8 در Composer Lock |
| Tailwind CSS | 4.x |
| Vite | 8.x |
| DaisyUI | نصب نیست |
| Mary UI | نصب نیست |
| npm lockfile | در مخزن فعلی وجود ندارد |
| npm config | `.npmrc` با `ignore-scripts=true` و `audit=true` |

آخرین نسخه منتشرشده Mary UI در زمان بررسی **2.9.11** است. Composer همین نسخه، `illuminate/support ^13.0` را پشتیبانی می‌کند و Laravel Service Provider را از طریق Composer Package Discovery معرفی می‌کند. نسخه نهایی باید در زمان اجرای PR دوباره بررسی و در `composer.lock` قفل شود.

## ۳. ممنوعیت نصب‌کننده خودکار

**اجرای `php artisan mary:install` در این پروژه ممنوع است.**

نصب‌کننده نسخه 2.9.11:
- دستور نصب Livewire را دوباره اجرا می‌کند، درحالی‌که Livewire 4 از قبل نصب است.
- Tailwind، DaisyUI و افزونه Vite را بدون محدودیت نسخه دوباره نصب می‌کند و ممکن است نسخه‌های فعلی را تغییر دهد.
- در نبود Starter Kit شناخته‌شده، Stub نمونه خود را روی `routes/web.php` کپی می‌کند؛ این فایل در دناگیت موجود و عملیاتی است.
- در حالت پیش‌فرض CSS را به `resources/css/app.css` اضافه می‌کند؛ این فایل بخشی از رابط فعلی DenaGate Live است.
- Stubهای نمونه Layout و Livewire را در ساختار منابع ایجاد می‌کند.

گزینه `--no-css` فقط تغییر CSS خودکار را متوقف می‌کند و خطر کپی Stub روی مسیر وب را رفع نمی‌کند؛ بنابراین اجرای Installer با یا بدون این گزینه مجاز نیست.

## ۴. تغییرات مجاز در CRM-01A

### ۴.۱. وابستگی Composer

- افزودن `robsontenorio/mary` با مبنای نسخه `2.9.11`.
- بررسی تغییرات `composer.json` و `composer.lock`؛ فقط وابستگی‌های لازم Mary UI اضافه شوند.
- Livewire، Laravel یا سایر وابستگی‌های اصلی بدون دلیل و تأیید تغییر نسخه ندهند.
- از Package Discovery خودکار Laravel استفاده شود؛ ثبت دستی Provider یا اجرای `vendor:publish --force` لازم نیست، مگر آنکه نیاز مشخصی با شواهد وجود داشته باشد.

### ۴.۲. وابستگی فرانت‌اند

- افزودن DaisyUI به‌عنوان وابستگی توسعه‌ای (devDependency) با نسخه سازگار با Tailwind CSS 4.
- نسخه‌های موجود `tailwindcss`، `@tailwindcss/vite` و `vite` حفظ شوند؛ نصب مجدد یا ارتقای کلی آن‌ها مجاز نیست.
- هیچ Alpine.js یا Livewire دیگری نصب نشود؛ Livewire فعلی مسئول Runtime موجود است.
- تنظیمات `.npmrc` تغییر نکند. سیاست Lockfile همان سیاست فعلی مخزن باقی بماند؛ فایل Lock جدید بدون نیاز CI یا تصمیم مستقل اضافه نشود.

### ۴.۳. CSS مستقل CRM

فایل جدید `resources/css/crm.css` ایجاد شود. این فایل باید:
- Tailwind را برای ورودی CRM فعال کند.
- DaisyUI را به‌صورت Plugin فعال کند.
- فقط منابع لازم Mary UI و View/Componentهای CRM را اسکن کند.
- Theme روشن را به‌عنوان Theme پیش‌فرض CRM تعریف کند.
- فونت فارسی موجود Vazirmatn را از Asset فعلی به‌صورت محدود به پوسته CRM معرفی کند.
- کلاس‌ها و توکن‌های CRM را زیر ریشه‌ای مانند `.denagate-crm` محدود کند.

الگوی اولیه مورد بررسی:

```css
@import 'tailwindcss';

@plugin "daisyui" {
    themes: light --default;
}

@source "../../vendor/robsontenorio/mary/src/View/Components/**/*.php";
@source "../views/crm/**/*.blade.php";
@source "../../app/Interfaces/Web/CRM/**/*.php";
```

این قطعه الگوی طراحی است و در PR باید با نسخه واقعی DaisyUI/Mary، مسیرهای نهایی View و خروجی Vite اعتبارسنجی شود. هیچ‌یک از خطوط Mary یا DaisyUI به `resources/css/app.css` منتقل نشود.

### ۴.۴. Vite و Layout مستقل

- فقط ورودی `resources/css/crm.css` به آرایه ورودی Vite اضافه شود؛ ورودی‌های فعلی `app.css`، `app.js` و `live.js` حذف یا جابه‌جا نشوند.
- Layout مستقل CRM در `resources/views/layouts/crm.blade.php` ایجاد شود.
- Layout شامل `lang="fa"`، `dir="rtl"`، منابع CSS مختص CRM و Directives رسمی Livewire باشد.
- Alpine یا اسکریپت Livewire دوباره بارگذاری نشود.
- پوسته CRM از CSS و Layout مینی‌اپ نمایندگان استفاده نکند.
- `resources/css/app.css`، `resources/js/app.js` و `resources/js/live.js` برای CRM بازنویسی نشوند.

### ۴.۵. مسیر و دسترسی

- مسیرهای CRM در فایل افزایشی `routes/crm.php` تعریف شوند و این فایل به‌صورت کنترل‌شده از `routes/web.php` فراخوانی شود.
- مسیر نمونه `/admin/crm` زیر Guard وب Laravel و میان‌افزار فعال بودن حساب ثبت شود.
- کنترل Super Admin با Action موجود `AssertCatalogManagementAccess` یا مسیر معادل مورد تأیید انجام شود؛ نقش عمومی admin یا مجوز `users.manage` کافی نیست.
- اگر برای Route به Middleware نیاز است، Middleware کوچک و اختصاصی CRM در لایه Interface ساخته شود و فقط به Action مالک Identity متکی باشد.
- Middleware مربوط به CRM باید در درخواست‌های بعدی Livewire نیز پایدار بماند. ثبت آن در Persistent Middleware نباید رفتار `EnsureLiveEnabled` و `EnsureLiveReseller` را تغییر دهد.
- هیچ مسیر CRM به `routes/live.php` یا دامنه Mini App اضافه نشود.
- صفحه فقط یک پیام «پوسته CRM آماده است» و اجزای آزمایشی Mary UI را نمایش دهد؛ هیچ داده مشتری یا نماینده از DB خوانده نشود.

## ۵. فایل‌های مورد انتظار

| مسیر | نوع تغییر |
|---|---|
| `composer.json` و `composer.lock` | افزودن Mary UI |
| `package.json` | افزودن DaisyUI با نسخه سازگار |
| `vite.config.js` | افزودن ورودی CSS CRM |
| `resources/css/crm.css` | فایل جدید |
| `resources/views/layouts/crm.blade.php` | Layout جدید |
| `resources/views/crm/shell.blade.php` | پوسته آزمایشی جدید |
| `routes/crm.php` | مسیرهای مستقل CRM |
| `routes/web.php` | فقط ثبت افزایشی مسیر CRM |
| `app/Interfaces/Web/CRM/` | Presenter و Middleware محدود CRM |
| `app/Providers/AppServiceProvider.php` | ثبت افزایشی Persistent Middleware CRM، در صورت نیاز |
| `tests/Feature/CRM/` | تست دسترسی و رندر پوسته |

فهرست نهایی فایل‌ها در زمان پیاده‌سازی با ساختار واقعی پروژه تطبیق داده شود؛ ایجاد فایل غیرضروری مجاز نیست.

## ۶. موارد صریحاً ممنوع در CRM-01A

- اجرای `php artisan mary:install` با هر گزینه.
- تغییر یا بازنویسی `routes/live.php`.
- تغییر رفتاری احراز هویت، نشست یا دامنه DenaGate Live.
- تغییر CSS یا JS موجود Mini App.
- افزودن Migration، جدول، Index یا داده نمونه به DB.
- فراخوانی Remnawave، Telegram Bot API یا هر Provider خارجی.
- خواندن یا نمایش کاربران، نمایندگان، مشتریان، سفارش‌ها، پرداخت‌ها یا کیف پول.
- افزودن دسترسی نمایندگان یا مدیران تفویض‌شده به CRM.
- Merge به `master` بدون Review، CI سبز و تأیید جداگانه.

## ۷. تست‌ها و معیار پذیرش

### وابستگی و فرانت‌اند

- [ ] Composer dependency resolution بدون ارتقای ناخواسته Laravel/Livewire موفق باشد.
- [ ] نسخه Mary UI و DaisyUI در فایل‌های وابستگی ثبت شوند.
- [ ] `npm run build` موفق باشد.
- [ ] خروجی CRM CSS مستقل تولید شود.
- [ ] صفحات موجود DenaGate Live همچنان از ورودی‌های قبلی استفاده کنند.
- [ ] هیچ CSS Mary/DaisyUI به `app.css` اضافه نشده باشد.

### دسترسی

- [ ] Super Admin واجد شرایط وارد `/admin/crm` شود.
- [ ] مهمان به مسیر CRM دسترسی نداشته باشد.
- [ ] عضو عادی، نماینده، مدیر تفویض‌شده و حساب غیرفعال پاسخ غیرمجاز دریافت کنند.
- [ ] تغییر نقش یا Allowlist در درخواست بعدی Livewire دسترسی را متوقف کند.
- [ ] درخواست Update جعلی Livewire نتواند Guard را دور بزند.
- [ ] تست‌های موجود DenaGate Live بدون تغییر موفق بمانند.

### کیفیت

- [ ] تست‌های Feature جدید با Pest موفق باشند.
- [ ] PHPStan و Pint موفق باشند.
- [ ] CI کامل PHP 8.4 و 8.5 سبز باشد.
- [ ] رندر RTL و فونت فارسی در مرورگر واقعی بازبینی شود.
- [ ] هیچ Query تجاری یا درخواست Provider در پوسته اجرا نشود.

## ۸. Rollback

- تغییرات وابستگی Composer و npm و ورودی Vite در یک PR مستقل و محدود باشند.
- در صورت Regression، PR با Revert کامل بازگردانده شود؛ فایل‌های فعلی Live از روی نسخه جدید بازسازی نشوند.
- چون این فاز Migration و تغییر داده ندارد، Rollback نباید نیازمند عملیات DB باشد.
- پیش از Merge، فهرست فایل‌های تغییرکرده باید با Scope این سند تطبیق داده شود.

## ۹. تحویل مورد انتظار

- پوسته وب CRM با Mary UI در مسیر مستقل.
- فقط Super Admin مورد اعتماد به آن دسترسی داشته باشد.
- CSS و Layout مستقل از Mini App نمایندگان.
- تست‌های دسترسی، Livewire update و Vite build سبز.
- هیچ داده تجاری هنوز به CRM متصل نشده باشد.

**وضعیت نهایی این سند:** برنامه CRM-01A آماده بررسی است. تأیید آن فقط مجوز اجرای همین فاز خواهد بود؛ CRM-01B و اتصال داده‌های مشتری/نماینده نیازمند تأیید جداگانه هستند.
