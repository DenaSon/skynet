<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Recognized Admin Bot grants; never substitutes for a trusted Super Admin. */
final readonly class AdminCapabilityAccess
{
    public const CAPABILITIES = [
        'users.view', 'users.manage', 'purchases.view', 'purchases.review',
        'topups.view', 'topups.review',
    ];

    public function __construct(private SuperAdminTrust $trust) {}

    public function allowed(User $user, string $permission): bool
    {
        if (! $user->is_active || ! in_array($permission, self::CAPABILITIES, true)) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return $this->trust->eligible($user);
        }

        if ($permission === 'users.view' && $user->hasPermissionTo('users.manage', 'web')) {
            return true;
        }

        if (! $user->hasPermissionTo($permission, 'web')) {
            return false;
        }

        // A decision grant without its matching view grant has no effect.
        return match ($permission) {
            'purchases.review' => $user->hasPermissionTo('purchases.view', 'web'),
            'topups.review' => $user->hasPermissionTo('topups.view', 'web'),
            default => true,
        };
    }

    public function require(int $userId, string $permission): void
    {
        $user = User::query()->with('telegramAccount')->find($userId);
        if ($user === null || $user->telegramAccount === null || ! $this->allowed($user, $permission)) {
            throw new AuthorizationException;
        }
    }

    /** @return list<string> */
    public function grants(User $user): array
    {
        return array_values(array_filter(self::CAPABILITIES,
            fn (string $permission): bool => $this->allowed($user, $permission)));
    }

    /** @return list<int> */
    public function reviewerIds(string $permission): array
    {
        if (! in_array($permission, ['purchases.review', 'topups.review'], true)) {
            return [];
        }

        $ids = [];
        // Preserve every eligible root, including older contacts that have
        // not yet been inserted into the Admin Bot contact directory.
        foreach ($this->trust->allowedTelegramIds() as $telegramId) {
            $root = User::query()->whereHas('telegramAccount',
                fn ($q) => $q->where('telegram_user_id', $telegramId))
                ->with('telegramAccount')->first();
            if ($root !== null && $this->trust->eligible($root)) {
                $ids[$root->id] = $root->id;
            }
        }

        $contacts = DB::table('telegram_bot_contacts')
            ->where('bot_key', 'admin')->where('delivery_status', 'reachable')
            ->pluck('user_id');
        foreach ($contacts as $id) {
            $user = User::query()->with('telegramAccount')->find((int) $id);
            if ($user !== null
                && $user->telegramAccount !== null
                && DB::table('telegram_bot_contacts')->where('user_id', $id)
                    ->where('bot_key', 'admin')->where('chat_id', $user->telegramAccount->telegram_user_id)
                    ->where('delivery_status', 'reachable')->exists()
                && $this->allowed($user, $permission)) {
                $ids[$user->id] = $user->id;
            }
        }

        return array_values($ids);
    }
}
