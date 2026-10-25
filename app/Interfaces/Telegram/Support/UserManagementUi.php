<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Application\ChangeUserStatus;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;

final class UserManagementUi
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly BrowseUsers $directory,
        private readonly ChangeUserStatus $changeStatus,
        private readonly UserStatusConfirmations $confirmations,
        private readonly MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1, bool $search = false): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $query = $search ? Cache::get($this->searchKey($actor->userId)) : null;

        if ($search && ! is_string($query)) {
            $this->menus->showUserNotice($bot, 'جست‌وجوی قبلی منقضی شده است. دوباره /finduser را وارد کنید.');

            return;
        }

        $results = $this->directory->page($actor->userId, $query, $page);
        $this->menus->showUserList($bot, $results, $search);
    }

    public function searchHelp(Nutgram $bot): void
    {
        if ($this->actor($bot) !== null) {
            $this->menus->showUserSearchHelp($bot);
        }
    }

    public function search(Nutgram $bot, string $term): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $term = trim($term);

        if ($term === '' || mb_strlen($term) > 64) {
            $this->menus->showUserNotice($bot, 'عبارت جست‌وجو باید بین ۱ تا ۶۴ کاراکتر باشد.');

            return;
        }

        Cache::put($this->searchKey($actor->userId), $term, now()->addMinutes(10));
        $this->browse($bot, 1, true);
    }

    public function details(Nutgram $bot, string $id, string $page = '1', string $mode = 'a'): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $userId = $this->positiveId($id);

        if ($userId === null) {
            $this->menus->showUserNotice($bot, 'شناسه کاربر نامعتبر است.');

            return;
        }

        $user = $this->directory->find($actor->userId, $userId);

        if ($user === null) {
            $this->menus->showUserNotice($bot, 'کاربر پیدا نشد.');

            return;
        }

        $this->menus->showUserDetails($bot, $user, $this->pageNumber($page), $mode === 's', $actor->canManageRoles(), $actor->isAdmin());
    }

    public function confirm(Nutgram $bot, string $id, string $page = '1', string $mode = 'a'): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $userId = $this->positiveId($id);
        $user = $userId === null ? null : $this->directory->find($actor->userId, $userId);

        if ($user === null || ! $user->canChangeStatus) {
            $this->menus->showUserNotice($bot, 'تغییر وضعیت این حساب مجاز نیست.');

            return;
        }

        $token = $this->confirmations->issue($actor->userId, $user->id, $user->active, $this->pageNumber($page), $mode === 's');
        $this->menus->showUserStatusConfirmation($bot, $user, $token, $this->pageNumber($page), $mode === 's');
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $pending = $this->confirmations->consume($token, $actor->userId);

        if ($pending === null) {
            $this->menus->showUserNotice($bot, 'تأیید نامعتبر یا منقضی شده است. از صفحه کاربر دوباره شروع کنید.');

            return;
        }

        try {
            $updated = $this->changeStatus->handle(
                $actor->userId,
                $pending['subject'],
                $pending['expected'],
                ! $pending['expected'],
            );
        } catch (ValidationException|AuthorizationException|ModelNotFoundException) {
            $this->menus->showUserNotice($bot, 'عملیات انجام نشد. مجوز یا وضعیت حساب تغییر کرده است.');

            return;
        }

        $this->menus->showUserDetails($bot, $updated, $pending['page'], $pending['search'], $actor->canManageRoles(), $actor->isAdmin());
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $telegramId = $this->privateUser->id($bot);

        if ($telegramId === null) {
            return null;
        }

        $actor = $this->resolve->handle($telegramId);

        if ($actor?->allows('users.view') !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function positiveId(string $id): ?int
    {
        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function pageNumber(string $page): int
    {
        return ctype_digit($page) ? max(1, min(100000, (int) $page)) : 1;
    }

    private function searchKey(int $actorId): string
    {
        return 'tg:users:search:'.$actorId;
    }
}
