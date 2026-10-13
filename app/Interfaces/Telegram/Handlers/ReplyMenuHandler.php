<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\BillingUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Interfaces\Telegram\Support\PlanAdminDrafts;
use App\Interfaces\Telegram\Support\PlanAdminUi;
use App\Interfaces\Telegram\Support\PlanCatalogUi;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\ProductAdminDrafts;
use App\Interfaces\Telegram\Support\ProductAdminUi;
use App\Interfaces\Telegram\Support\RbacUi;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ShopUi;
use App\Interfaces\Telegram\Support\SubscriptionUi;
use App\Interfaces\Telegram\Support\UserManagementUi;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use SergiX44\Nutgram\Nutgram;

final class ReplyMenuHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly ReplyMenuRenderer $replyMenus,
        private readonly PendingShopVolume $pendingVolume,
        private readonly PlanAdminDrafts $planDrafts,
        private readonly ProductAdminDrafts $productDrafts,
        private readonly PendingBuilderSelection $pendingBuilder,
    ) {}

    /**
     * True when the text is a recognized reply-menu action, including denied requests.
     * Unknown text continues to the existing custom-volume handler.
     */
    public function handle(Nutgram $bot, string $text): bool
    {
        if (config('telegram.hybrid_menu_enabled', false) !== true
            || ! in_array($text, ReplyMenuLabels::all(), true)) {
            return false;
        }

        $telegramId = $this->privateUser->id($bot);

        if ($telegramId === null) {
            return true; // A copied button label in a group must not elicit a response.
        }

        $actor = $this->resolve->handle($telegramId);

        if ($actor === null) {
            $this->replyMenus->showUnauthenticated($bot);

            return true;
        }

        // A button tap is navigation, never a custom volume submission.
        $this->pendingVolume->forget($actor->userId);
        $this->planDrafts->forget($actor->userId);
        $this->productDrafts->forget($actor->userId);
        $this->pendingBuilder->forget($actor->userId);

        switch ($text) {
            case ReplyMenuLabels::BUY:
                app(ShopUi::class)->open($bot);

                return true;

            case ReplyMenuLabels::BUILDER:
                if (! in_array('reseller', $actor->roles, true)) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(ResellerBuilderUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::CATALOG:
                app(PlanCatalogUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::SUBSCRIPTIONS:
                app(SubscriptionUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::ACCOUNT:
                app(AccountHandler::class)->__invoke($bot);

                return true;

            case ReplyMenuLabels::INVITE:
                app(InviteHandler::class)->__invoke($bot);

                return true;

            case ReplyMenuLabels::SETTINGS:
                app(SettingsHandler::class)->__invoke($bot);

                return true;

            case ReplyMenuLabels::HOME:
                $this->replyMenus->showHome($bot, $actor);

                return true;

            case ReplyMenuLabels::ADMIN:
                if (! $actor->isAdmin()) {
                    $this->deny($bot, $actor);

                    return true;
                }

                $this->replyMenus->showAdmin($bot, $actor);

                return true;

            case ReplyMenuLabels::USERS:
                if (! $actor->isAdmin()) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(UserManagementUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::PRODUCTS:
                if (! $actor->canManageRoles()) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(ProductAdminUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::PLANS:
                if (! $actor->canManageRoles()) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(PlanAdminUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::ROLES:
                if (! $actor->canManageRoles()) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(RbacUi::class)->browse($bot);

                return true;

            case ReplyMenuLabels::WALLET:
                if (! in_array('reseller', $actor->roles, true)) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(BillingUi::class)->wallet($bot);

                return true;

            case ReplyMenuLabels::TOPUPS:
                if (! $actor->canManageRoles() || config('billing.topups_enabled', false) !== true) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(BillingUi::class)->reviews($bot);

                return true;

            case ReplyMenuLabels::RECEIPTS:
                if (! $actor->canManageRoles() || config('sales.manual_bank_review_enabled', false) !== true) {
                    $this->deny($bot, $actor);

                    return true;
                }

                app(ManualPaymentUi::class)->list($bot);

                return true;
        }

        return false;
    }

    private function deny(Nutgram $bot, TelegramActor $actor): void
    {
        // Stale keyboards are not authorization tokens. Refresh on any denied action.
        $bot->sendMessage(
            text: 'دسترسی مجاز نیست. منوی شما به‌روز شد.',
            reply_markup: $this->replyMenus->homeKeyboard($actor),
        );
    }
}
