<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ManageAnnouncements
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return array{items: list<object>, page: int, pages: int, total: int} */
    public function listing(int $actorId, int $requestedPage = 1): array
    {
        $this->access->handle($actorId);

        $total = DB::table('announcements')->count();
        $pages = max(1, (int) ceil($total / 5));
        $page = min(max($requestedPage, 1), $pages);
        $items = DB::table('announcements')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->offset(($page - 1) * 5)->limit(5)->get()->all();

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function find(int $actorId, int $id): ?object
    {
        $this->access->handle($actorId);

        return DB::table('announcements')->where('id', $id)->first();
    }

    public function create(int $actorId, string $title, string $body, bool $publish = false): int
    {
        $this->access->handle($actorId);
        [$title, $body] = self::validatedContent($title, $body);

        return (int) DB::table('announcements')->insertGetId([
            'title' => $title,
            'body' => $body,
            'status' => $publish ? 'published' : 'draft',
            'published_at' => $publish ? now() : null,
            'created_by' => $actorId,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(int $actorId, int $id, int $revision, string $title, string $body): void
    {
        $this->access->handle($actorId);
        [$title, $body] = self::validatedContent($title, $body);

        DB::transaction(function () use ($id, $revision, $title, $body): void {
            $row = DB::table('announcements')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->status === 'reseller_sent' || (int) $row->revision !== $revision) {
                throw self::stale();
            }

            DB::table('announcements')->where('id', $id)->update([
                'title' => $title,
                'body' => $body,
                'revision' => $revision + 1,
                'updated_at' => now(),
            ]);
        });
    }

    public function changeStatus(int $actorId, int $id, int $revision, string $action): void
    {
        $this->access->handle($actorId);
        if (! in_array($action, ['publish', 'unpublish'], true)) {
            throw self::stale();
        }

        DB::transaction(function () use ($id, $revision, $action): void {
            $row = DB::table('announcements')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->revision !== $revision
                || ($action === 'publish' && $row->status !== 'draft')
                || ($action === 'unpublish' && $row->status !== 'published')) {
                throw self::stale();
            }

            DB::table('announcements')->where('id', $id)->update([
                'status' => $action === 'publish' ? 'published' : 'draft',
                // Preserve last publication for operator history; republishing resets it.
                'published_at' => $action === 'publish' ? now() : $row->published_at,
                'revision' => $revision + 1,
                'updated_at' => now(),
            ]);
        });
    }

    /** Only active resellers who started the private Sales Bot and can still be reached. */
    public function resellerRecipientCount(int $actorId): int
    {
        $this->access->handle($actorId);

        return $this->eligibleResellerQuery()->count('u.id');
    }

    /**
     * A reseller-only announcement is immutable and never appears in the
     * public announcement list. The existing delivery table owns the queue.
     *
     * @return array{id: int, recipients: int}
     */
    public function sendToResellers(int $actorId, string $title, string $body): array
    {
        $this->access->handle($actorId);
        [$title, $body] = self::validatedContent($title, $body);

        if (config('telegram_notifications.enabled') !== true
            || config('telegram.sales.enabled') !== true
            || ! is_string(config('telegram.sales.token'))
            || config('telegram.sales.token') === ''
            || config('telegram.sales.token') === config('nutgram.token')) {
            throw ValidationException::withMessages([
                'announcement' => 'ارسال پیام از بات فروش در حال حاضر فعال نیست.',
            ]);
        }

        return DB::transaction(function () use ($actorId, $title, $body): array {
            $ids = $this->eligibleResellerQuery()->orderBy('u.id')->pluck('u.id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages([
                    'announcement' => 'نماینده فعال و قابل‌دسترسی در بات فروش پیدا نشد.',
                ]);
            }

            $now = now();
            $announcementId = (int) DB::table('announcements')->insertGetId([
                'title' => $title,
                'body' => $body,
                // Deliberately NOT 'published': ordinary buyers never see this.
                'status' => 'reseller_sent',
                'published_at' => null,
                'created_by' => $actorId,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $deliveries = [];
            foreach ($ids as $index => $userId) {
                $deliveries[] = [
                    'event_key' => "reseller:ann:{$announcementId}:user:{$userId}",
                    'event_type' => 'ResellerAnnouncement',
                    'recipient_user_id' => (int) $userId,
                    'bot_key' => 'sales',
                    'claim_id' => null,
                    'order_id' => null,
                    'state' => 'pending',
                    'attempts' => 0,
                    // Bound the outbound rate without another scheduler/job.
                    'available_at' => $now->copy()->addSeconds($index * 3),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($deliveries) >= 100) {
                    DB::table('telegram_notification_deliveries')->insert($deliveries);
                    $deliveries = [];
                }
            }
            if ($deliveries !== []) {
                DB::table('telegram_notification_deliveries')->insert($deliveries);
            }

            // Immediately enqueue committed intents with their original staggered
            // due times. The periodic dispatcher remains a recovery fallback if
            // Redis is temporarily unavailable after the database commit.
            DB::afterCommit(static function () use ($announcementId): void {
                try {
                    DB::table('telegram_notification_deliveries')
                        ->where('event_type', 'ResellerAnnouncement')
                        ->where('event_key', 'like', "reseller:ann:{$announcementId}:user:%")
                        ->select(['id', 'available_at'])
                        ->chunkById(100, static function ($rows): void {
                            foreach ($rows as $row) {
                                DispatchTelegramNotification::dispatch((int) $row->id)
                                    ->delay(Carbon::parse((string) $row->available_at));
                            }
                        });
                } catch (Throwable $exception) {
                    // The outbox is already committed. Do not report a failed
                    // send or retry inline: scheduled recovery remains available.
                    Log::warning('Reseller announcement initial enqueue failed; scheduled recovery retained.', [
                        'announcement_id' => $announcementId,
                        'exception' => $exception::class,
                    ]);
                }
            });

            return ['id' => $announcementId, 'recipients' => $ids->count()];
        });
    }

    /** @return array{sent: int, pending: int, failed: int, uncertain: int} */
    public function resellerDeliveryStats(int $actorId, int $announcementId): array
    {
        $this->access->handle($actorId);
        $rows = DB::table('telegram_notification_deliveries')
            ->where('event_type', 'ResellerAnnouncement')
            ->where('event_key', 'like', "reseller:ann:{$announcementId}:user:%")
            ->selectRaw('state, COUNT(*) as total')->groupBy('state')->pluck('total', 'state');

        return [
            'sent' => (int) ($rows['sent'] ?? 0),
            'pending' => (int) ($rows['pending'] ?? 0) + (int) ($rows['processing'] ?? 0),
            'failed' => (int) ($rows['unavailable'] ?? 0) + (int) ($rows['dead'] ?? 0),
            'uncertain' => (int) ($rows['sending'] ?? 0),
        ];
    }

    private function eligibleResellerQuery(): Builder
    {
        return DB::table('users as u')
            ->join('model_has_roles as m', function (JoinClause $join): void {
                $join->on('m.model_id', '=', 'u.id')->where('m.model_type', User::class);
            })
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->join('telegram_accounts as ta', 'ta.user_id', '=', 'u.id')
            ->join('telegram_bot_contacts as tc', function (JoinClause $join): void {
                $join->on('tc.user_id', '=', 'u.id')
                    ->on('tc.chat_id', '=', 'ta.telegram_user_id')
                    ->where('tc.bot_key', 'sales')
                    ->where('tc.delivery_status', 'reachable');
            })
            ->where('r.name', 'reseller')
            ->where('r.guard_name', 'web')
            ->where('u.is_active', true)
            ->distinct();
    }

    /** @return array{string, string} */
    private static function validatedContent(string $title, string $body): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($title === '' || mb_strlen($title) > 120
            || $body === '' || mb_strlen($body) > 1500) {
            throw ValidationException::withMessages([
                'announcement' => 'عنوان باید ۱ تا ۱۲۰ و متن باید ۱ تا ۱۵۰۰ نویسه باشد.',
            ]);
        }

        return [$title, $body];
    }

    private static function stale(): ValidationException
    {
        return ValidationException::withMessages([
            'announcement' => 'اطلاعیه تغییر کرده یا دیگر در دسترس نیست. صفحه را تازه کنید.',
        ]);
    }
}
