<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveActiveUser;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** User-owned Sales Panel applications; role assignment belongs solely to SP-02. */
final readonly class SalesPanelRequests
{
    /** Shared threshold for both initial entry and locked submission; invalid config fails closed. */
    public static function minimumWalletBalanceRials(): int
    {
        $value = config('sales.panel_minimum_wallet_toman', 350_000);
        if (! is_int($value) && (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
            throw new \InvalidArgumentException('Invalid sales.panel_minimum_wallet_toman: expected positive whole tomans.');
        }

        $tomans = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX - 9, 10)],
        ]);
        if ($tomans === false) {
            throw new \InvalidArgumentException('Invalid sales.panel_minimum_wallet_toman: expected positive whole tomans.');
        }

        return $tomans * 10;
    }

    public function __construct(
        private ResolveActiveUser $users,
        private ResolveSalesBuyer $buyers,
        private ViewWallet $wallets,
    ) {}

    /** @return array{id:int,status:string,first_name:string,last_name:string,mobile:string}|null */
    public function find(int $userId): ?array
    {
        $this->users->handle($userId);
        $row = DB::table('sales_panel_requests')->where('user_id', $userId)->first();

        return $row === null ? null : $this->map($row);
    }

    /** Only confirmed wallet liability postings count; pending top-ups do not. */
    public function hasMinimumWalletBalance(int $userId, bool $lockForUpdate = false): bool
    {
        return $this->wallets->hasAtLeast($userId, self::minimumWalletBalanceRials(), $lockForUpdate);
    }

    /**
     * Advisory CTA only. Submission still enforces the locked balance and role checks.
     * Uses the same ENV-backed minimum and confirmed wallet ledger as the real gate.
     */
    public function canRequestWithCurrentBalance(int $userId): bool
    {
        return $this->buyers->isMember($userId)
            && $this->find($userId) === null
            && $this->hasMinimumWalletBalance($userId);
    }

    /** @return array{id:int,status:string,first_name:string,last_name:string,mobile:string} */
    public function submit(int $userId, string $firstName, string $lastName, string $mobile): array
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $mobile = self::normalizeMobile($mobile);
        if (! self::validName($firstName) || ! self::validName($lastName) || $mobile === null) {
            throw ValidationException::withMessages(['application' => 'Enter a valid first name, last name and mobile.']);
        }

        return DB::transaction(function () use ($userId, $firstName, $lastName, $mobile): array {
            $this->users->handle($userId, true);
            if (! $this->buyers->isMember($userId)) {
                throw new AuthorizationException;
            }

            // A previously submitted request retains its status even if the
            // applicant later spends wallet credit. Only new submissions need
            // to pass the current balance check.
            $existing = DB::table('sales_panel_requests')->where('user_id', $userId)->first();
            if ($existing !== null) {
                return $this->map($existing);
            }
            if (! $this->hasMinimumWalletBalance($userId, true)) {
                throw ValidationException::withMessages([
                    'wallet_balance' => 'At least '.number_format(intdiv(self::minimumWalletBalanceRials(), 10))
                        .' tomans of confirmed wallet credit is required.',
                ]);
            }

            DB::table('sales_panel_requests')->insertOrIgnore([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'mobile' => $mobile,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('sales_panel_requests')->where('user_id', $userId)->first();
            if ($row === null) {
                throw ValidationException::withMessages(['application' => 'Application could not be saved.']);
            }

            return $this->map($row);
        });
    }

    public static function validName(string $name): bool
    {
        return mb_strlen($name) >= 2 && mb_strlen($name) <= 60
            && preg_match('/\A[\p{L}\p{M}][\p{L}\p{M} \x{200C}-]{1,59}\z/uD', $name) === 1;
    }

    public static function normalizeMobile(string $value): ?string
    {
        $value = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (str_starts_with($value, '+98')) {
            $value = '0'.substr($value, 3);
        } elseif (str_starts_with($value, '0098')) {
            $value = '0'.substr($value, 4);
        } elseif (str_starts_with($value, '98') && strlen($value) === 12) {
            $value = '0'.substr($value, 2);
        }

        return preg_match('/\A09[0-9]{9}\z/D', $value) === 1 ? $value : null;
    }

    /** @return array{id:int,status:string,first_name:string,last_name:string,mobile:string} */
    private function map(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'mobile' => (string) $row->mobile,
        ];
    }
}
