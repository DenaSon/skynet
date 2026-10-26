<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
    public function __construct(private ResolveSalesBuyer $buyers) {}

    public function handle(int $userId, int $senderTelegramId, ?int $contactTelegramId, string $phone): int
    {
        if (config('trial.enabled', false) !== true) {
            throw ValidationException::withMessages(['trial' => 'دریافت تست رایگان فعلاً فعال نیست.']);
        }

        $key = config('trial.phone_hmac_key');
        $limit = config('trial.daily_limit');
        if (! is_string($key) || strlen($key) < 32 || ! is_int($limit) || $limit < 1 || $limit > 1000) {
            throw ValidationException::withMessages(['trial' => 'تست رایگان فعلاً در دسترس نیست.']);
        }

        if ($userId < 1 || $senderTelegramId < 1 || $contactTelegramId !== $senderTelegramId
            || ! $this->buyers->isMember($userId)
            || ! DB::table('telegram_accounts')->where('user_id', $userId)
                ->where('telegram_user_id', $senderTelegramId)->exists()) {
            throw ValidationException::withMessages(['trial' => 'احراز هویت برای دریافت تست معتبر نیست.']);
        }

        $number = self::normalizePhone($phone);
        $fingerprint = hash_hmac('sha256', 'denagate:trial-phone:v1:'.$number, $key);

        try {
            // Atomic admission cap across different users, plus two DB UNIQUE
            // constraints. The lock is shared through the configured cache.
            return Cache::lock('trial:admission', 10)->block(3, function () use ($userId, $fingerprint, $limit): int {
                return DB::transaction(function () use ($userId, $fingerprint, $limit): int {
                    if (DB::table('trial_claims')->where('user_id', $userId)
                        ->orWhere('phone_fingerprint', $fingerprint)->exists()) {
                        throw ValidationException::withMessages(['trial' => 'برای این حساب یا شماره قبلاً تست ثبت شده است.']);
                    }

                    // A prior paid order or verified settlement disqualifies the
                    // user. Never mistake wallet top-ups for a purchase.
                    $purchased = DB::table('sales_orders as orders')
                        ->where('orders.buyer_user_id', $userId)
                        ->where(function ($query): void {
                            $query->whereIn('orders.status', ['paid', 'fulfilled'])
                                ->orWhereExists(function ($settled): void {
                                    $settled->selectRaw('1')->from('sales_order_settlements as wallet')
                                        ->whereColumn('wallet.sales_order_id', 'orders.id');
                                })
                                ->orWhereExists(function ($settled): void {
                                    $settled->selectRaw('1')->from('sales_bank_settlements as bank')
                                        ->whereColumn('bank.sales_order_id', 'orders.id');
                                });
                        })->exists();

                    if ($purchased) {
                        throw ValidationException::withMessages(['trial' => 'تست رایگان فقط برای مشتریان بدون خرید قبلی است.']);
                    }

                    $from = now('Asia/Tehran')->startOfDay()->utc();
                    if (DB::table('trial_claims')->where('created_at', '>=', $from)->count() >= $limit) {
                        throw ValidationException::withMessages(['trial' => 'ظرفیت تست رایگان امروز تکمیل شده است.']);
                    }

                    return (int) DB::table('trial_claims')->insertGetId([
                        'user_id' => $userId,
                        'phone_fingerprint' => $fingerprint,
                        'state' => 'reserved',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }, 3);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['trial' => 'درخواست‌های زیادی در حال بررسی است. دوباره تلاش کنید.']);
        } catch (QueryException $exception) {
            // Do not reveal whether another identity owns a phone fingerprint.
            if (in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                throw ValidationException::withMessages(['trial' => 'برای این حساب یا شماره قبلاً تست ثبت شده است.']);
            }

            throw $exception;
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
