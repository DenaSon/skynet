<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;

final class ReadPublishedAnnouncements
{
    /** @return list<object> */
    public function latest(): array
    {
        return $this->visible()->orderByDesc('published_at')->orderByDesc('id')
            ->limit(3)->get(['id', 'title', 'body', 'published_at'])->all();
    }

    public function find(int $id): ?object
    {
        if ($id < 1) {
            return null;
        }

        return $this->visible()->where('id', $id)
            ->first(['id', 'title', 'body', 'published_at']);
    }

    private function visible(): \Illuminate\Database\Query\Builder
    {
        return DB::table('announcements')->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
