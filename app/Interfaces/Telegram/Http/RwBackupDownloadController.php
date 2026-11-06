<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Http;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Infrastructure\Backup\RwBackupSsh;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class RwBackupDownloadController
{
    public function __construct(
        private ResolveTelegramActor $actors,
        private RwBackupSsh $backups,
    ) {}

    public function __invoke(Request $request, string $token): StreamedResponse
    {
        abort_unless(config('rw_backup.enabled') === true
            && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1, 404);

        // One-use high-entropy bearer link, delivered only in a verified private
        // Super Admin chat. No guest listing or archive enumeration is possible.
        $key = 'rwbackup:download:'.hash('sha256', $token);
        $grant = Cache::pull($key);
        abort_unless(is_array($grant), 404);

        $telegramId = $grant['telegramId'] ?? null;
        $userId = $grant['userId'] ?? null;
        $id = $grant['id'] ?? null;
        abort_unless(is_int($telegramId) && is_int($userId) && is_string($id)
            && RwBackupSsh::validId($id), 404);

        $actor = $this->actors->handle($telegramId);
        abort_unless($actor?->canManageRoles() === true && $actor->userId === $userId, 404);

        try {
            $metadata = $this->backups->info($id);
        } catch (Throwable) {
            abort(404);
        }

        $response = response()->streamDownload(
            fn () => $this->backups->stream($id),
            $id.'.tar',
            [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'X-Checksum-SHA256' => $metadata['sha256'],
                'Content-Length' => (string) $metadata['size'],
            ],
        );

        return $response;
    }
}
