<?php

namespace App\Modules\Onboarding\Application;

use App\Modules\Identity\Application\RegisterTelegramMember;
use App\Modules\Referral\Application\AttributeReferral;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class JoinFromTelegram
{
    public function handle(int $telegramId, string $firstName, ?string $referralCode = null): OnboardingResult
    {
        try {
            return DB::transaction(function () use ($telegramId, $firstName, $referralCode): OnboardingResult {
                $member = app(RegisterTelegramMember::class)->handle($telegramId, $firstName);

                // Returning members, including deactivated or legacy accounts, never acquire a new referrer.
                $referred = $member['created'] && app(AttributeReferral::class)->handle($referralCode, $member['user_id']);

                return new OnboardingResult($member['user_id'], $member['created'], $referred);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent /start callback may have created the binding first.
            $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramId)->first();

            if ($account === null) {
                throw $exception;
            }

            return new OnboardingResult((int) $account->user_id, false, false);
        }
    }
}
