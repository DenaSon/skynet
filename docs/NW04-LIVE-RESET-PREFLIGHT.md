# ممیزی نهایی و آماده‌سازی تست Reinstall Node — 2026-10-18

## وضعیت و مرجع

- آخرین `master` بررسی‌شده: `d7d3e329dda6263bc0ad4323809558c6939f64c7`، شامل NW-01 تا NW-04 و PR #104، #106، #107 و #108.
- **نتیجه:** Code Review و CI قبلی موفق؛ **Live E2E هنوز انجام نشده و برای پاک‌کردن VPS/Node فعلی تأیید عملیاتی نداریم**. این سند تنها Review و Preflight است و مجوز حذف Node یا Reinstall VPS نیست.
- سناریوی پیشنهادی کاربر: آزمایش Node قبلاً فعال `194.5.192.133` روی پنل `rw.coreflare.ir`، Reinstall کامل سیستم‌عامل VPS توسط اپراتور، سپس راه‌اندازی **فقط از Admin Bot**. آدرس یادشده نقش *هدف پیشنهادی* دارد؛ پیش از عملیات باید مالکیت و تطابق IP در پنل و کنسول VPS دوباره بررسی شود.
- راهنمای اصلی ادامه: [NW04-OPERATOR-ACCEPTANCE.md](NW04-OPERATOR-ACCEPTANCE.md). منابع رسمی: [Remnawave Node](https://docs.rw/install/remnawave-node/) و [Docker Engine / Ubuntu](https://docs.docker.com/engine/install/ubuntu/).

## یافته‌های Review

| موضوع | شواهد کد / مستندات | نتیجه قبل از تست |
| --- | --- | --- |
| تفکیک و مجوزها | `AdminNodeSetupUi`، `NodeSetupReadiness`، `ManageNodeInstallation`، `CompleteNodeSetup` | Super Admin در گفتگوی خصوصی، تأیید جداگانه نصب/Node/Host؛ رفتار اصلی با تست‌های Mock پوشش داده شده است. |
| Secrets | حذف پیام رمز، Laravel Crypt، SSH pinned ED25519، Cache دارای TTL | طراحی و تست‌های کد قابل قبول؛ حذف پیام واقعی و اتصال از محیط Runflare **تأیید نشده** است. |
| نصب و Node image | `NodeSshInstaller`، `nw02-install-remote.sh` | تصویر `remnawave/node:3.4.2` موجود است. Docker Ubuntu 26.04 را پشتیبانی می‌کند؛ دسترسی شبکه، apt و اجرای واقعی تصویر باید Live چک شود. |
| مدیریت پورت | Installer: `NODE_PORT=2222`؛ در اصلاح 2026-10-20، `CompleteNodeSetup` همین پورت را صریحاً به Action ثبت نود می‌دهد؛ رفتار مسیر دستی حفظ می‌شود. | تست واقعی اپراتور اختلاف 443/2222 را تأیید کرد؛ اصلاح دستی اتصال را برقرار کرد. پس از Deploy اصلاح کد، ثبت تازه باید بدون اصلاح دستی با پورت ذخیره‌شده `2222` و Connected واقعی تأیید شود. |
| Firewall | نصب‌کننده Firewall را تغییر نمی‌دهد | حفظ SSH و محدودکردن `2222/TCP` فقط به IP پنل، و اجازه پورت Inbound آزمایشی، با فایروال ارائه‌دهنده یا مسیر موجود اپراتور انجام شود. |
| Config Profile / Host | Node به Profile و Inbound موجود وصل می‌شود، Host غیرفعال ساخته می‌شود | Profile/Inbound و Internal Squad موجود را **حفظ** کنیم، وضعیت Host قدیمی را قبل از حذف بررسی کنیم، Binding واقعی Host جدید را جداگانه تأیید کنیم. |
| Queue / Cache | Queue تک‌تلاش، درخواست رمز در Cache تا ۲ ساعت؛ وضعیت تا ۳ ساعت | Queue Worker، Backend مشترک Cache و `APP_KEY` در Webhook و Worker باید واقعاً بررسی شود؛ قطع ارتباط/TTL نیازمند بررسی دستی است، نه Retry خودکار. |
| Feature Flags | `NODE_SSH_SETUP_ENABLED=false`، `NODE_SSH_INSTALL_ENABLED=false`، `REMNAWAVE_WRITES_ENABLED=false` پیش‌فرض | تغییر هر Flag نیازمند تصمیم اپراتور است؛ **روشن‌کردن Provider Writes سایر Actionهای مدیریتی را نیز مجاز می‌کند**. بعد از تست به حالت قبلی بازگردانید. |
| Live Client | تست گذشته یک مسیر Shadowsocks دستی را تأیید کرده است؛ تست آن با ویزارد جدید یکی نیست | فقط نتیجه تست جدیدِ اشتراک آزمایشی، خروجی IP نود و افزایش ترافیک واقعی را پذیرش نهایی بدانید. |

**محدودیت بررسی:** دسترسی SSH به Runflare یا VPS و دسترسی authenticated به داده‌های واقعی پنل در این Review انجام نشده؛ هیچ وضعیت لحظه‌ای سرور/اشتراک ادعا نمی‌شود. تست‌های CI Mock/Fake هستند و سلامت Production را اثبات نمی‌کنند.

## ترتیب ایمن پاک‌سازی VPS و پنل

1. **اول شناسایی و Snapshot**: IP، نام/UUID Node، Host متصل، User/Subscription فعال و شمار آن‌ها، Profile، Inbound، Internal Squad و پورت واقعی Shadowsocks را در پنل *صرفاً خواندنی* یادداشت کنید. لاگ یا Screenshot شامل Password/SECRET_KEY/Subscription URL ارسال نشود.
2. **بررسی وابستگی**: تأیید کتبی اپراتور که فقط کاربر آزمایشی وابسته است و قطعی قابل‌قبول است. اگر کاربر واقعی یا Host دیگری به Node وابسته باشد، متوقف شویم.
3. **راه بازگشت**: Snapshot/Backup پیکربندی پنل و VPS (در صورت نیاز)، دسترسی کنسول ارائه‌دهنده برای Reinstall/Recovery، و دسترسی مدیریت مستقل پنل را تأیید کنیم. Snapshot نباید Secret را در گفتگو آشکار کند.
4. **پاک‌سازی پنل**: پس از ثبت مشخصات، ابتدا Host آزمایشی را **Disable** کنید؛ از طریق مسیر تأییددار پنل/بات آن را حذف یا از کار بیندازید، سپس Node را Disable و با تأیید مستقل **Delete** کنید. اینکه حذف Node خودکار چه وابستگی‌هایی را پاک می‌کند نباید حدس زده شود؛ فهرست Hostها و کاربران دوباره خوانده شود. Profile/Inbound/Squad موجود را حذف نکنید.
5. **Reinstall VPS**: تنها پس از تأیید گام‌های بالا، اپراتور از کنسول ارائه‌دهنده OS Reinstall کامل انجام دهد؛ IP جدید/ثابت، SSH port، Root password authentication و اثر انگشت **جدید** سرور بررسی شود. اثر انگشت قدیمی بعد از Reinstall معتبر نیست.
6. **Preflight از Admin Bot**: SSH tools/خروجی Runflare، Docker Registry و صف را بدون نصب واقعی بررسی کنیم. سپس دو Flag نصب و در زمان نیاز Gate نوشتن Provider را موقتاً با تصمیم اپراتور تنظیم کنیم.
7. **راه‌اندازی با بات**: Wizard → تأیید Fingerprint → SSH Check → انتخاب Profile/Inbound موجود → تأیید نصب → **running** → تأیید ثبت Node → Provider Connected → انتخاب پورت Inbound → ایجاد Host غیرفعال → Binding/Squad Verification → فعال‌سازی تأییددار Host.
8. **قبولی واقعی**: اتصال یک کلاینت آزمایشی بدون VPN دیگر؛ IP خروجی VPS، Traffic واقعی، خطاهای پنل/Node، ثبات اتصال و پورت Firewall بررسی شوند. سپس Flagهای حساس به حالت اولیه برگردند و گزارش بی‌خطر ثبت شود.

## توقف آزمون (Stop Conditions)

- کاربران غیرآزمایشی، وابستگی ناشناخته Node/Host، نبود Backup/Console یا تغییر IP غیرمنتظره.
- فقدان `ssh`/`ssh-keyscan`، outbound SSH یا Queue/Cache مشترک در Production Runtime.
- ناسازگاری fingerprint، نبود مجوز Root password SSH، عدم دسترسی Docker Registry یا عدم انطباق نسخه Node.
- Node Port پنل با `2222` مطابقت ندارد، یا `2222/TCP` نمی‌تواند فقط برای پنل محدود شود.
- Remnawave POST نتیجه نامطمئن دارد، Node Connected نمی‌شود یا Host Binding نامعتبر است؛ **بدون ارسال دوباره POST، از پنل reconciliation انجام شود**.

## نتیجه Review

**Source/CI: PASS با محدودیت‌های فوق. Live/Destructive: NOT STARTED.** تغییر کد یا معماری در این گام مجاز/انجام‌شده نیست؛ اگر Preflight واقعی ناسازگاری را اثبات کرد، پیش از اصلاح کد یک PR کوچک و تأیید صریح اپراتور لازم است.
