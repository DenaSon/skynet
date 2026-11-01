<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * A bounded, owner-verified page of live subscription statuses.
 * Counts cover only this page, never all reseller subscriptions.
 */
final readonly class ReadResellerServiceHealth
{
    private const PAGE_SIZE = 6;

    private const LOW_BYTES = 350 * 1024 * 1024;

    private const EXPIRING_SOON_SECONDS = 3 * 86400;

    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private ResolveSalesBuyer $buyers,
        private ListMyServices $services,
        private GetSubscriptionUsage $usage,
        private ManageResellerServiceLabels $labels,
    ) {}

    /**
     * @return array{
     *  total:int,localActive:int,checked:int,active:int,expired:int,attention:int,unknown:int,expiringSoon:int,
     *  nextBeforeOrderId:?int,rows:list<array{orderId:int,name:string,status:string,action:string,remainingSeconds:?int}>
     * }
     */
    public function handle(SubscriptionViewer $viewer, ?int $beforeOrderId = null, ?SubscriptionCursorPage $visiblePage = null): array
    {
        $buyerId = $this->authorize->userId($viewer);
        if (! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }

        $counts = $this->services->counts($viewer);
        // A web search may supply its own server-resolved, owned six-item page.
        // The same per-service GET still independently verifies ownership.
        $page = $visiblePage ?? $this->services->handle($viewer, self::PAGE_SIZE, $beforeOrderId);
        if (count($page->items) > self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Too many services for a health page.');
        }
        $labels = $this->labels->forOrders($buyerId, array_map(
            static fn (SubscriptionSummary $item): int => $item->orderId, $page->items,
        ));
        $rows = [];
        $active = $expired = $attention = $unknown = $expiringSoon = 0;

        foreach ($page->items as $item) {
            $status = '⚪ نامشخص';
            $action = '';
            $remainingSeconds = null;
            if ($item->status === 'active') {
                try {
                    // Existing owner-verified GET rechecks identity after reading RW.
                    // Never emit the remote credential, username or raw provider body.
                    $live = $this->usage->handle($viewer, $item->orderId);
                    $secondsToExpiry = (new DateTimeImmutable($live->expireAt))->getTimestamp() - time();
                    $remainingBytes = max(0, $live->limitBytes - $live->usedBytes);

                    if ($secondsToExpiry > 0 && $secondsToExpiry <= self::EXPIRING_SOON_SECONDS
                        && $live->status !== 'EXPIRED') {
                        $remainingSeconds = $secondsToExpiry;
                        $expiringSoon++;
                    }

                    if ($secondsToExpiry <= 0 || $live->status === 'EXPIRED') {
                        $status = '🔴 منقضی';
                        $action = 'تمدید یا پیگیری';
                        $expired++;
                    } elseif ($live->status === 'DISABLED') {
                        $status = '🟠 غیرفعال';
                        $action = 'بررسی وضعیت';
                        $attention++;
                    } elseif ($live->limitBytes > 0 && $remainingBytes === 0) {
                        $status = '🟠 حجم تمام‌شده';
                        $action = 'بررسی تمدید';
                        $attention++;
                    } elseif ($live->status === 'LIMITED') {
                        $status = '🟠 محدود شده';
                        $action = 'بررسی علت محدودیت';
                        $attention++;
                    } elseif ($live->status === 'ACTIVE') {
                        $status = '🟢 فعال';
                        $active++;
                        if ($secondsToExpiry <= 7 * 86400) {
                            $action = 'انقضا در ۷ روز آینده';
                            $attention++;
                        } elseif ($live->limitBytes > 0 && $remainingBytes < self::LOW_BYTES) {
                            $action = 'حجم رو به پایان';
                            $attention++;
                        }
                    } else {
                        $unknown++;
                    }
                } catch (Throwable) {
                    // A failed RW read is unknown, never a fictitious active state.
                    $unknown++;
                }
            } else {
                $unknown++;
            }

            $rows[] = [
                'orderId' => $item->orderId,
                'name' => $labels[$item->orderId] ?? $item->productName,
                'status' => $status,
                'action' => $action,
                'remainingSeconds' => $remainingSeconds,
            ];
        }

        return [
            'total' => $counts['total'],
            'localActive' => $counts['activeRecorded'],
            'checked' => count($rows),
            'active' => $active,
            'expired' => $expired,
            'attention' => $attention,
            'unknown' => $unknown,
            'expiringSoon' => $expiringSoon,
            'nextBeforeOrderId' => $page->nextBeforeOrderId,
            'rows' => $rows,
        ];
    }
}
