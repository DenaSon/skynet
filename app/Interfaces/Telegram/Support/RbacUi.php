<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Application\CreateRole;
use App\Modules\Identity\Application\ManageRoles;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\ToggleMemberRole;
use App\Modules\Identity\Application\ToggleRolePermission;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;

final class RbacUi
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly ManageRoles $roles,
        private readonly BrowseUsers $users,
        private readonly CreateRole $create,
        private readonly ToggleRolePermission $changePermission,
        private readonly ToggleMemberRole $changeMember,
        private readonly RbacConfirmations $confirmations,
        private readonly MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $this->menus->showRolesList($bot, $this->roles->page($actor->userId, $page));
    }

    public function detail(Nutgram $bot, string $roleId, string $page = '1'): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $role = $this->roles->find($actor->userId, (int) $roleId);

        if ($role === null) {
            $this->menus->showRoleNotice($bot, 'نقش پیدا نشد.');

            return;
        }

        $this->menus->showRoleDetails($bot, $role, $this->roles->permissions(), $this->page($page));
    }

    public function createHelp(Nutgram $bot): void
    {
        if ($this->actor($bot) !== null) {
            $this->menus->showRoleCreateHelp($bot);
        }
    }

    public function createRequest(Nutgram $bot, string $name): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $name = strtolower(trim($name));

        if (preg_match('/^[a-z][a-z0-9-]{2,49}$/D', $name) !== 1 || in_array($name, ['member', 'admin', 'super-admin'], true)) {
            $this->menus->showRoleNotice($bot, 'نام نقش نامعتبر یا محافظت‌شده است. مثال: /newrole reseller');

            return;
        }

        $token = $this->confirmations->issue($actor->userId, 'create', name: $name);
        $this->menus->showRbacConfirmation($bot, "ایجاد نقش «{$name}» بدون هیچ مجوز اولیه", $token);
    }

    public function permissionRequest(Nutgram $bot, string $roleId, string $permissionId): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $role = $this->roles->find($actor->userId, (int) $roleId);
        $permission = collect($this->roles->permissions())->first(fn ($entry): bool => $entry->id === (int) $permissionId);

        if ($role === null || $role->protected || $permission === null) {
            $this->menus->showRoleNotice($bot, 'امکان تغییر مجوز این نقش وجود ندارد.');

            return;
        }

        $assigned = in_array($permission->name, $role->permissions, true);
        $verb = $assigned ? 'حذف' : 'اعطای';
        $token = $this->confirmations->issue($actor->userId, 'permission', $role->id, $permission->id, $assigned);
        $this->menus->showRbacConfirmation($bot, "{$verb} مجوز «{$permission->name}» برای نقش «{$role->name}»", $token);
    }

    public function userRoles(Nutgram $bot, string $userId, string $page = '1'): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $user = $this->users->find($actor->userId, (int) $userId);

        if ($user === null) {
            $this->menus->showRoleNotice($bot, 'کاربر پیدا نشد.');

            return;
        }

        $this->menus->showUserRoles($bot, $user, $this->roles->page($actor->userId, $this->page($page)));
    }

    public function userRoleRequest(Nutgram $bot, string $userId, string $roleId, string $page = '1'): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $user = $this->users->find($actor->userId, (int) $userId);
        $role = $this->roles->find($actor->userId, (int) $roleId);

        if ($user === null || $role === null || $role->name === 'super-admin' || in_array('super-admin', $user->roles, true)) {
            $this->menus->showRoleNotice($bot, 'تغییر نقش این حساب از طریق ربات مجاز نیست.');

            return;
        }

        $assigned = in_array($role->name, $user->roles, true);
        $verb = $assigned ? 'حذف' : 'افزودن';
        $token = $this->confirmations->issue($actor->userId, 'member', $user->id, $role->id, $assigned, $this->page($page));
        $this->menus->showRbacConfirmation($bot, "{$verb} نقش «{$role->name}» برای کاربر #{$user->id}", $token);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $request = $this->confirmations->consume($token, $actor->userId);

        if ($request === null) {
            $this->menus->showRoleNotice($bot, 'تأیید نامعتبر یا منقضی شده است. دوباره اقدام کنید.');

            return;
        }

        try {
            switch ($request['type']) {
                case 'create':
                    $role = $this->create->handle($this->roles->authorize($actor->userId), $request['name']);
                    $this->detail($bot, (string) $role->getKey());

                    return;

                case 'permission':
                    $changed = $this->changePermission->handle($actor->userId, $request['subject'], $request['option'], $request['expected']);
                    $this->detail($bot, (string) $changed->id);

                    return;

                case 'member':
                    $this->changeMember->handle($actor->userId, $request['subject'], $request['option'], $request['expected']);
                    $this->userRoles($bot, (string) $request['subject'], (string) $request['page']);

                    return;
            }
        } catch (ValidationException|AuthorizationException|ModelNotFoundException) {
            $this->menus->showRoleNotice($bot, 'عملیات رد شد؛ مجوز یا وضعیت نقش تغییر کرده است.');

            return;
        }

        $this->menus->showRoleNotice($bot, 'عملیات نامعتبر است.');
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);

        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function page(string $page): int
    {
        return ctype_digit($page) ? max(1, min(100000, (int) $page)) : 1;
    }
}
