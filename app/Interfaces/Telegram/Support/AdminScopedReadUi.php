<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Application\ReadDelegatedAdminReports;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Provisioning\Application\ReadAdminProviderOverview;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Permission-specific read screens. Root retains its existing full menus. */
final readonly class AdminScopedReadUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private MenuRenderer $menus,
        private ReadDelegatedAdminReports $reports,
        private ReadAdminWalletSummary $summary,
        private BrowseCustomerWallets $wallets,
        private ReadAdminProviderOverview $provider,
    ) {}

    public function reports(Nutgram $bot): void
    {
        $actor = $this->actor($bot, 'reports.view');
        if ($actor === null) {
            return;
        }

        try {
            $counts = $this->reports->handle($actor->userId);
            $text = '<b>📊 گزارش‌های عملیاتی</b>'
                ."\n\nکاربران: ".number_format($counts['users'])
                ."\nکاربران فعال: ".number_format($counts['activeUsers'])
                ."\nسفارش‌های ثبت‌شده: ".number_format($counts['orders'])
                ."\nسفارش‌های پرداخت‌شده: ".number_format($counts['paidOrders'])
                ."\nسرویس‌های فعال: ".number_format($counts['activeServices']);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $text = '📊 گزارش‌ها\n\nدریافت گزارش فعلاً ممکن نیست.';
        }

        $this->show($bot, $text, 'rbac2:reports');
    }

    public function finance(Nutgram $bot, string $page = '1'): void
    {
        $actor = $this->actor($bot, 'finance.view');
        if ($actor === null) {
            return;
        }
        if (! ctype_digit($page) || (int) $page < 1 || (int) $page > 10000) {
            $this->show($bot, 'صفحه معتبر نیست.', 'rbac2:finance:1');

            return;
        }

        try {
            $data = $this->summary->handle($actor->userId);
            $rows = $this->wallets->page($actor->userId, (int) $page);
            $text = '<b>💳 گزارش کیف پول‌ها</b>'
                ."\n\nکل کیف پول‌ها: ".number_format($data['walletsTotal'])
                ."\nدارای موجودی: ".number_format($data['walletsPositive'])
                ."\nمجموع مانده: ".TelegramUi::toman($data['balanceRials']).' تومان'
                ."\n\n<b>فهرست مانده‌ها</b>";
            foreach (array_slice($rows, 0, 8) as $row) {
                $text .= "\n#".$row['id'].' · '.TelegramUi::escape(mb_substr($row['name'], 0, 40))
                    .' · '.TelegramUi::toman($row['balance_rials']).' تومان';
            }
            if ($rows === []) {
                $text .= "\nموردی یافت نشد.";
            }
            $keyboard = InlineKeyboardMarkup::make();
            if ((int) $page > 1) {
                $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'rbac2:finance:'.((int) $page - 1)));
            }
            if (count($rows) > 8) {
                $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'rbac2:finance:'.((int) $page + 1)));
            }
            $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'rbac2:finance:'.$page))
                ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
            $this->render($bot, $text, $keyboard);

            return;
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->show($bot, '💳 گزارش مالی\n\nدریافت اطلاعات ممکن نیست.', 'rbac2:finance:1');
        }
    }

    public function servers(Nutgram $bot): void
    {
        $actor = $this->actor($bot, 'servers.view');
        if ($actor === null) {
            return;
        }

        try {
            $overview = $this->provider->handle($actor->userId);
            if ($overview->state !== 'available') {
                $text = '🖥️ وضعیت سرورها\n\nدریافت وضعیت سرورها فعلاً ممکن نیست.';
            } else {
                $text = '<b>🖥️ وضعیت سرورها</b>'
                    ."\n\nنودها: ".$overview->nodes
                    .' | متصل: '.$overview->connectedNodes
                    ."\nهاست‌ها: ".$overview->hosts;
                foreach (array_slice($overview->nodeSummaries, 0, 20) as $node) {
                    $text .= "\n".($node['connected'] ? '🟢 ' : '⚪ ')
                        .TelegramUi::escape(mb_substr($node['name'], 0, 45));
                }
            }
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $text = '🖥️ وضعیت سرورها\n\nدریافت وضعیت سرورها فعلاً ممکن نیست.';
        }

        // No node, host, DNS, provider mutation or configuration controls.
        $this->show($bot, $text, 'rbac2:servers');
    }

    private function actor(Nutgram $bot, string $permission): ?TelegramActor
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null || ! $actor->allows($permission)) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function show(Nutgram $bot, string $text, string $refresh): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: $refresh))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $this->render($bot, $text, $keyboard);
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
