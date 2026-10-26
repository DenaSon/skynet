<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Modules\Identity\Application\AssertTrialMember;
use App\Modules\Sales\Application\HasPaidPurchaseHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TRIAL-01: one irrevocable reservation per member AND shared Telegram phone.
 *
 * No provider write, paid order, billing journal, contact persistence, or
 * Telegram send. The explicitly enabled next phase may consume this reservation.
 */
final readonly class ReserveFreeTrial
{
    public function __construct(private AssertTrialMember $identity, private HasPaidPurchaseHistory $history) {}

    public function handle(int $userId, int $senderTelegramId, ?int $contactTelegramId, #[\SensitiveParameter] string $phone): int
    {
        $contact = $this->contact($userId, $senderTelegramId, $contactTelegramId, $phone);

        return $this->reserve($userId, $senderTelegramId, $contact['fingerprint']);
    }

    /** Validated native self-contact only; the UI keeps no raw phone in its draft.
     * @return array{fingerprint:string,masked:string}
     */
    public function contact(int $userId, int $senderTelegramId, ?int $contactTelegramId, #[\SensitiveParameter] string $phone): array
    {
        $this->assertEligible($userId, $senderTelegramId);
        if ($contactTelegramId !== $senderTelegramId) {
            throw ValidationException::withMessages(['trial' => 'لطفاً فقط شماره حساب تلگرام خودتان را با دکمه ارسال کنید.']);
        }
        $number = self::normalizePhone($phone);

        return [
            'fingerprint' => hash_hmac('sha256', 'denagate:trial-phone:v1:'.$number, (string) config('trial.phone_hmac_key')),
            'masked' => substr($number, 0, 3).str_repeat('*', strlen($number) - 7).substr($number, -4),
        ];
    }

    public function assertEligible(int $userId, int $telegramId): void
    {
        $this->assertConfigured();
        $this->identity->handle($userId, $telegramId);
        if ($this->history->handle($userId)) {
            throw ValidationException::withMessages(['trial' => 'تست رایگان فقط برای مشتریان بدون خرید قبلی است.']);
        }
    }

    /** Internal server draft fingerprint, never accepted from Telegram input. */
    public function reserve(int $userId, int $telegramId, string $fingerprint): int
    {
        $this->assertConfigured();
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw ValidationException::withMessages(['trial' => 'لطفاً دریافت تست را دوباره شروع کنید.']);
        }
        try {
            return DB::transaction(function () use ($userId, $telegramId, $fingerprint): int {
                // SQL is authoritative even with a per-process cache or an expired cache lease.
                $day = now('Asia/Tehran')->toDateString();
                DB::table('trial_admission_days')->insertOrIgnore(['day' => $day, 'reserved_count' => 0]);
                $quota = DB::table('trial_admission_days')->where('day', $day)->lockForUpdate()->first();
                $this->assertEligible($userId, $telegramId);

                if (DB::table('trial_claims')->where('user_id', $userId)
                    ->orWhere('telegram_user_id', $telegramId)->orWhere('phone_fingerprint', $fingerprint)->exists()) {
                    throw ValidationException::withMessages(['trial' => 'برای این حساب یا شماره قبلاً تست ثبت شده است.']);
                }
                if ($quota === null || (int) $quota->reserved_count >= (int) config('trial.daily_limit')) {
                    throw ValidationException::withMessages(['trial' => 'ظرفیت تست رایگان امروز تکمیل شده است.']);
                }
                $id = (int) DB::table('trial_claims')->insertGetId([
                    'user_id' => $userId, 'telegram_user_id' => $telegramId,
                    'phone_fingerprint' => $fingerprint, 'state' => 'reserved',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('trial_admission_days')->where('day', $day)->increment('reserved_count');

                return $id;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                throw ValidationException::withMessages(['trial' => 'برای این حساب یا شماره قبلاً تست ثبت شده است.']);
            }
            throw $exception;
        }
    }

    private function assertConfigured(): void
    {
        if (config('trial.enabled', false) !== true) {
            throw ValidationException::withMessages(['trial' => 'دریافت تست رایگان فعلاً فعال نیست.']);
        }
        $key = config('trial.phone_hmac_key');
        $limit = config('trial.daily_limit');
        if (! is_string($key) || strlen($key) < 32 || trim($key) === ''
            || $key === config('app.key') || ! is_int($limit) || $limit < 1 || $limit > 1000) {
            throw ValidationException::withMessages(['trial' => 'تست رایگان فعلاً در دسترس نیست.']);
        }
    }

    private static function normalizePhone(string $raw): string
    {
        if (strlen($raw) > 40) {
            throw ValidationException::withMessages(['trial' => 'شماره ارسال‌شده معتبر نیست.']);
        }

        $digits = preg_replace('/[ \t\r\n()+-]/', '', $raw);
        if (! is_string($digits) || preg_match('/\A[0-9]{8,17}\z/D', $digits) !== 1) {
            throw ValidationException::withMessages(['trial' => 'شماره ارسال‌شده معتبر نیست.']);
        }

        if (str_starts_with($raw, '+')) {
            $normalized = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $normalized = '+'.substr($digits, 2);
        } elseif (preg_match('/\A09[0-9]{9}\z/D', $digits) === 1) {
            $normalized = '+98'.substr($digits, 1);
        } elseif (preg_match('/\A9[0-9]{9}\z/D', $digits) === 1) {
            $normalized = '+98'.$digits;
        } else {
            $normalized = '+'.$digits;
        }

        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $normalized) !== 1) {
            throw ValidationException::withMessages(['trial' => 'شماره ارسال‌شده معتبر نیست.']);
        }

        return $normalized;
    }
}
