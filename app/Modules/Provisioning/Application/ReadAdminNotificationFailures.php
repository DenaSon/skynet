<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** OPS-CMD-01A: bounded read of terminal notification evidence, no mutations. */
final readonly class ReadAdminNotificationFailures
{
    private const PAGE_SIZE = 5;

    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return array{total:int,page:int,pages:int,items:list<array<string,int|string|null>>} */
    public function handle(int $actorId, string $requestedPage): array
    {
        $this->access->handle($actorId);

        if (preg_match('/\A[1-9][0-9]{0,3}\z/D', $requestedPage) !== 1
            || (int) $requestedPage > 1000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }

        $query = DB::table('telegram_notification_deliveries')
            ->whereIn('state', ['dead', 'unavailable']);
        $total = (int) (clone $query)->count();
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min((int) $requestedPage, $pages);

        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)
            ->get([
                'id', 'event_type', 'recipient_user_id', 'bot_key', 'state',
                'attempts', 'last_failure', 'order_id', 'claim_id',
                'topup_id', 'panel_request_id', 'updated_at',
            ])->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'eventType' => (string) $row->event_type,
                'recipientId' => (int) $row->recipient_user_id,
                'bot' => (string) $row->bot_key,
                'state' => (string) $row->state,
                'attempts' => (int) $row->attempts,
                'reason' => (string) ($row->last_failure ?? ''),
                'orderId' => $row->order_id === null ? null : (int) $row->order_id,
                'claimId' => $row->claim_id === null ? null : (int) $row->claim_id,
                'topupId' => $row->topup_id === null ? null : (int) $row->topup_id,
                'panelRequestId' => $row->panel_request_id === null ? null : (int) $row->panel_request_id,
                'updatedAt' => (string) $row->updated_at,
            ])->all();

        return compact('total', 'page', 'pages', 'items');
    }
}
