<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Provisioning\Application\ReadCrmLocalServices;
use App\Modules\Sales\Application\ReadCrmAccountHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ReadCrmProfile
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadCrmDirectory $directory,
        private ReadCrmAccountHistory $history,
        private ReadCrmLocalServices $services,
        private AssertBillingAccess $billingAccess,
        private BrowseCustomerWallets $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handle(int $actorId, int $subjectId, bool $reseller, array $filters): array
    {
        $this->access->handle($actorId);
        // Local service status has no reporting-period semantics.
        if (($filters['tab'] ?? null) === 'services') {
            $filters['period'] = 'all';
            $filters['from'] = null;
            $filters['until'] = null;
        }

        $filters = Validator::make($filters, [
            'tab' => ['required', Rule::in($reseller ? ['overview', 'orders', 'payments', 'contacts', 'services'] : ['overview', 'orders', 'payments', 'services'])],
            'period' => ['required', Rule::in(['today', 'yesterday', '7', '30', 'all', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'until' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:from'],
            'status' => ['required', Rule::in(['all', 'pending_payment', 'paid', 'fulfilled', 'refunded', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', Rule::in(['all', 'yes', 'no'])],
            'purchase' => ['required', Rule::in(['all', 'yes', 'no'])],
            'groupContacts' => ['required', 'boolean'],
            'page' => ['required', 'integer', 'min:1', 'max:100000'],
        ])->validate();

        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $from = match ($filters['period']) {
            'today' => $today->copy(),
            'yesterday' => $today->copy()->subDay(),
            '7' => $today->copy()->subDays(6),
            '30' => $today->copy()->subDays(29),
            'custom' => Carbon::parse($filters['from'], 'Asia/Tehran')->startOfDay(),
            default => Carbon::parse('1970-01-01', 'Asia/Tehran'),
        };
        $until = match ($filters['period']) {
            'yesterday' => $today->copy(),
            'custom' => Carbon::parse($filters['until'], 'Asia/Tehran')->startOfDay()->addDay(),
            default => $today->copy()->addDay(),
        };
        $from = $from->utc();
        $until = $until->utc();

        return DB::transaction(function () use ($actorId, $subjectId, $reseller, $filters, $from, $until): array {
            $account = $this->directory->profileAccount($actorId, $subjectId, $reseller);
            if ($account === null) {
                throw new NotFoundHttpException;
            }

            $data = ['account' => $account];
            $page = (int) $filters['page'];
            switch ($filters['tab']) {
                case 'overview':
                    $data['summary'] = $this->history->summary($actorId, $subjectId, $from, $until, $reseller);
                    $data['wallet'] = null;
                    $data['walletState'] = 'denied';
                    try {
                        $this->billingAccess->financeViewer($actorId);
                        $data['wallet'] = $this->wallets->balanceForUser($actorId, $subjectId);
                        $data['walletState'] = 'available';
                    } catch (AuthorizationException) {
                        // Independent financial read permission.
                    } catch (QueryException) {
                        $data['walletState'] = 'unavailable';
                    }
                    break;
                case 'orders':
                    $data['rows'] = $this->history->orderPage($actorId, $subjectId, $from, $until, $filters['status'], trim((string) ($filters['search'] ?? '')), $page);
                    break;
                case 'payments':
                    $data['rows'] = $this->history->settlementPage($actorId, $subjectId, $from, $until, $page);
                    break;
                case 'services':
                    $data['rows'] = $this->services->page($actorId, $subjectId, $page);
                    break;
                case 'contacts':
                    // The locked subject ID, never a browser-supplied reseller filter.
                    $data['rows'] = $this->directory->handle($actorId, 'contacts', [
                        'period' => $filters['period'],
                        'from' => $filters['from'] ?? null,
                        'until' => $filters['until'] ?? null,
                        'search' => $filters['search'] ?? '',
                        'status' => 'all',
                        'telegram' => 'all',
                        'purchase' => $filters['purchase'],
                        'phone' => $filters['phone'],
                        'groupContacts' => $filters['groupContacts'],
                        'reseller' => $subjectId,
                        'orderStatus' => $filters['status'],
                        'sort' => 'order_id',
                        'direction' => 'desc',
                        'page' => $page,
                    ])['rows'];
                    break;
            }

            return $data;
        });
    }
}
