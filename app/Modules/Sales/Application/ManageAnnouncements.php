<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            if ($row === null || (int) $row->revision !== $revision) {
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
