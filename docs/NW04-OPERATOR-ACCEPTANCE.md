# NW-04 — پذیرش راه‌اندازی سرور از بات (Runbook)


## Runflare laravel-nginx runtime SSH preparation (2026-10-18)

The stock **Runflare `laravel-nginx`** image in this staging deployment is Debian 13 and does not ship `openssh-client`. Its file mount also presented the Git-executable `resources/scripts/nw01-askpass.sh` with mode 0644. Source commit alone therefore does **not** establish runnable SSH.

- The existing service's **postStart** field should invoke only `/bin/sh /var/www/resources/scripts/prepare-ssh-runtime.sh` once the source commit is deployed. Do **not** replace its Command/Args, Nginx/Supervisor configuration or Laravel Queue definition.
- The idempotent script checks for both `ssh` and `ssh-keyscan`; if either is absent, it installs Debian `openssh-client` via apt-get (requires the lifecycle hook to run as root and Debian package network reachability). It then `chmod 755`s the mounted Askpass file and verifies all three prerequisites. Installation errors abort this hook; they must be inspected in deployment/lifecycle logs, not ignored.
- postStart might run concurrently with the app/Queue startup; **do not enable the Node SSH flags until the new container is running and both the CLI and effective Webhook/Queue execution environments have been verified**. Changing settings/redeploying can discard manual apt/chmod fixes; this hook repeats them on new containers.
- Safe verification after deploy, before enabling flags:
  ```sh
  cd /var/www
  ssh -V
  command -v ssh-keyscan
  test -x resources/scripts/nw01-askpass.sh && echo ASKPASS_OK
  supervisorctl status
  php artisan tinker --execute='dump(["queue"=>config("queue.default"),"cache"=>config("cache.default"),"ssh_setup"=>config("services.remnawave.node_ssh_setup_enabled"),"ssh_install"=>config("services.remnawave.node_ssh_install_enabled"),"provider_writes"=>config("services.remnawave.writes_enabled")]);'
  ```
- This is an **Alpha/Beta temporary runtime solution**, not a prebuilt-image guarantee. After it passes in the new container, perform the existing read-only SSH/fingerprint preflight. Keep `NODE_SSH_SETUP_ENABLED`, `NODE_SSH_INSTALL_ENABLED` and `REMNAWAVE_WRITES_ENABLED` **OFF until the appropriate operator-approved test step**. VPS reinstall and Remnawave deletion still require separate explicit operator approval.
- Do not commit credentials, open SSH ports, touch existing VPS/Node, or assume CI shell syntax checks are actual Runflare connectivity tests.


**وضعیت:** تست‌های کد/CI و پذیرش واقعی جدا هستند. این سند مجوز تغییر Production یا روشن‌کردن Feature Flag نیست. اجرای دستورهای زیر تنها روی یک VPS آزمایشی خالی و با تصمیم صریح اپراتور انجام شود.

## محدوده قابل‌قبول

- همان **Admin Bot** و همان مسیرهای موجود Node/Host/Profile؛ فقط Super Admin قابل‌اعتماد در چت خصوصی. هیچ عملیات فروش، پرداخت، جابه‌جایی کاربران یا تغییر خودکار Squad انجام نمی‌شود.
- دریافت IP عمومی، پورت SSH، کاربر `root` و رمز در بات؛ حذف موفق پیام رمز، تأیید اثرانگشت ED25519 با کنسول مستقل VPS، انتخاب Profile و Inbound؛ تأیید جداگانه نصب.
- Queue یک Job یک‌بارمصرف آغاز می‌کند؛ نصب روی VPS از طریق `systemd-run` ادامه دارد. وضعیت با `بررسی وضعیت نصب` و از منوی Nodes با `ادامه راه‌اندازی اخیر` (تا پایان اعتبار Cache، حدود ۳ ساعت) قابل بازیابی است.
- پس از وضعیت **running**، ثبت Node با تأیید جداگانه و کنترل واقعی `isConnected` در Provider. سپس تأیید جداگانه برای ساخت Host **غیرفعال** با پورت واقعی Inbound. Host را فقط با کنترل مستقل Squad/اشتراک و تأیید جداگانه HOST-02 فعال کنید.

## پیش‌نیازهای استقرار

1. بررسی در کانتینر واقعی Laravel: `command -v ssh`، `command -v ssh-keyscan`، `test -x resources/scripts/nw01-askpass.sh`؛ اتصال خروجی TCP SSH به VPS مورد آزمایش باید برقرار باشد. وجود OpenSSH یا اتصال واقعی از روی CI قابل نتیجه‌گیری نیست.
2. Queue Worker فعال و پیکربندی شده با Backend مشترک؛ Cache مشترک و پایدار میان Webhook و Worker با `APP_KEY` یکسان؛ `QUEUE_CONNECTION` نباید `sync` باشد.
3. VPS **خالی** Ubuntu/Debian با دسترسی root و systemd، دسترسی مخازن Docker و فضای آزاد کافی. نصب روی VPS دارای Node یا Compose قبلی باید متوقف شود. SSH و آدرس سرور را قبل از اجرای نصب جداگانه بازبینی کنید.
4. پنل Remnawave با Config Profile و Inbound آماده. درباره سازگاری نسخه تصویر `remnawave/node:3.4.2` با نسخه فعلی پنل پیش از عملیات واقعی تأیید بگیرید. هیچ Upgrade خودکاری جز نصب اولیه روی سرور آزمایشی در Scope نیست.
5. Flagهای `NODE_SSH_SETUP_ENABLED`، `NODE_SSH_INSTALL_ENABLED` و `REMNAWAVE_WRITES_ENABLED` در مخزن پیش‌فرض **false** هستند. روشن‌کردن سراسری Provider Writes روی Production می‌تواند سایر عملیات مدیریتی را نیز آزاد کند؛ فقط در محیط/زمان کنترل‌شده با تصمیم اپراتور تغییر دهید. Merge به تنهایی این Flagها را تغییر نمی‌دهد.

## سناریوهای پذیرش عملی — به ترتیب

1. **مجوز و UI:** بات در چت گروهی/Member/ادمین معمولی مسیر نصب را رد کند. بازگشت، لغو، پیام منقضی و ورود مجدد به فرم قدیمی Node/Host بدون تداخل کار کند.
2. **Preflight:** رمز پس از ارسال از تلگرام پاک شود و اثرانگشت با کنسول ارائه‌دهنده VPS مطابقت کند؛ با اثرانگشت نادرست، IP خصوصی یا رمز نادرست نباید نصب آغاز شود. رمز واقعی را در لاگ/اسکرین‌شات نفرستید.
3. **نصب:** با یک تأیید شروع شود؛ پس از شروع، وضعیت را در چت و از منوی Nodes بازیابی کنید. روی VPS فقط خروجی‌های **غیرمحرمانه** را کنترل کنید: `systemctl status parsa-node-bootstrap --no-pager`، `cat /opt/parsa-node-install/status`، `docker ps --filter name=remnanode`. هیچ `.env`، SECRET_KEY یا رمز عبوری را نمایش ندهید.
4. **عدم تکرار:** دوباره‌زدن دکمه تأیید یا قطع‌وصل کردن Callback/Queue نباید نصب، Node POST یا Host POST دیگری بسازد. خطای مبهم باید «نیاز به بررسی دستی» نشان دهد.
5. **ارتباط با پنل:** یک Node تازه با همان نام/IP و Profile/Inbound دقیق ثبت شود. تا وقتی پنل Connected و enabled را واقعاً گزارش نکرده است، دکمه ساخت Host قابل استفاده نباشد.
6. **Host و Squad:** Port دقیق ورودی واقعی را انتخاب کنید؛ Host تازه در پنل باید `isDisabled=true` و binding معتبر به Profile/Inbound داشته باشد. اگر شناسه‌ها `null`، نامعتبر یا `00000000-0000-0000-0000-000000000000` غیرقابل تأیید هستند، تکمیل خودکار را تأیید نکنید. مجوز Internal Squad را مستقل بررسی کنید؛ هیچ کاربر فعلی را خودکار تغییر ندهید.
7. **شبکه و کلاینت:** **پورت مدیریتی Node (پیش‌فرض 2222/TCP)** را با فایروال ارائه‌دهنده فقط برای IP پنل مجاز کنید و دسترسی SSH خودتان را حفظ کنید. پورت ورودی مشتری را مطابق TCP/UDP واقعی Inbound باز کنید. پس از فعال‌سازی تأییددار Host، فقط یک کاربر آزمایشی ایزوله و اشتراک او را با کلاینت واقعی و IP خروجی VPS بررسی کنید. پاسخ‌دادن پنل و Connected بودن Node به‌تنهایی تست اینترنت مشتری نیست.
8. **گزارش پذیرش:** زمان، نسخه Commit/Deploy، نام Node/Host آزمایشی، نتیجه هر سناریو و خطاهای بی‌خطر را ثبت کنید؛ هیچ توکن، لینک اشتراک خصوصی یا رمز را در گزارش نگذارید.

## خطا، بازیابی و Rollback

- **Pending/launching/uncertain:** اول تازه‌سازی وضعیت، سپس بررسی اجرای Queue/دستگاه. کنترل `launching` فقط Poll خواندنی است؛ درخواست نصب مجدد به‌صورت خودکار ارسال نمی‌شود.
- **Failed/نامشخص:** بدون پاک‌کردن فایل‌ها یا تکرار نصب، وضعیت `/opt/parsa-node-install/status` و سرویس `parsa-node-bootstrap` را در کنسول VPS بررسی کنید؛ اپراتور برای Cleanup دستی VPS آزمایشی تصمیم می‌گیرد.
- **خطای مبهم Provider POST:** لیست Node/Host را از پنل بررسی کنید؛ عملیات خودکار Retry ندارد. شناسه موجود را با حدس‌زدن یا در Cache دست‌کاری نکنید.
- **انقضای Cache (۳ ساعت) یا قطع Worker:** رابط «ادامه اخیر» منقضی می‌شود؛ برای VPS قبلاً دستکاری‌شده، آغاز مجدد کورکورانه ممنوع است. بازیابی بلندمدت و Retrying پیشرفته خارج از MVP است.
- **بازگشت اضطراری:** Flagهای نصب را خاموش کنید، Worker را پس از ارزیابی ایمن متوقف کنید و در صورت ضرورت Commit را revert کنید. خاموش‌کردن Flag هیچ VPS یا Node نصب‌شده‌ای را حذف نمی‌کند. عملیات حذف/تعویض واقعی تنها با اجازه جداگانه.

## وضعیت قابل اعلام

- **Source/CI:** بسته‌شدن مرحله به معنای سبزبودن تست‌های کد، API فیک، Queue فیک و Route/RBAC است.
- **Live:** تا وقتی سناریوهای فوق روی VPS و بات واقعی تکمیل نشده‌اند، NW-04 را **آماده پذیرش عملیاتی / Live pending** اعلام کنید، نه Live PASS.
