<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ARI-02B: local, manually confirmed Cloud Server ID <-> Remnawave Node UUID.
 * Provider access is GET-only; this class never calls either write client.
 */
final readonly class ManageAdminArianetCloudLinks
{
    private const TABLE = 'arianet_cloud_node_links';

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminArianetCloud $cloud,
        private ManageAdminNodes $nodes,
    ) {}

    /** @return array{nodeUuid:string}|null */
    public function linked(int $actorId, int $serverId): ?array
    {
        $this->access->handle($actorId);
        self::serverId($serverId);
        $nodeUuid = DB::table(self::TABLE)->where('cloud_server_id', $serverId)->value('node_uuid');

        return is_string($nodeUuid) ? ['nodeUuid' => $nodeUuid] : null;
    }

    /**
     * Present a one-time candidate selection, with direct-IP suggestions first.
     * Hostnames are explicitly *not* assumed to resolve to the Cloud IPv4.
     *
     * @return array{cloudName:string,cloudIpv4:string,items:list<array{uuid:string,name:string,address:string,match:string}>,page:int,pages:int}
     */
    public function candidates(int $actorId, int $serverId, int $page): array
    {
        $this->access->handle($actorId);
        self::serverId($serverId);
        self::page($page);
        $cloud = $this->cloud->detail($actorId, $serverId);
        $ip = $cloud['ipv4'];
        if (! is_string($ip)) {
            throw new RuntimeException('Cloud IPv4 is unavailable; cannot propose a Node.');
        }

        $linked = DB::table(self::TABLE)->pluck('cloud_server_id', 'node_uuid')->all();
        $nodes = $this->nodes->cloudLinkCandidates($actorId);
        $items = [];
        foreach ($nodes as $node) {
            // A Node linked to another server must never be selectable.
            $otherServer = $linked[$node['uuid']] ?? null;
            if ($otherServer !== null && (int) $otherServer !== $serverId) {
                continue;
            }
            $items[] = [
                'uuid' => $node['uuid'],
                'name' => self::label($node['name']),
                'address' => $node['address'],
                'match' => self::match($node['address'], $ip),
            ];
        }

        usort($items, static fn (array $a, array $b): int =>
            (self::rank($a['match']) <=> self::rank($b['match']))
            ?: strcmp($a['name'], $b['name']));

        $pages = max(1, (int) ceil(count($items) / 8));
        if ($page > $pages) {
            throw new InvalidArgumentException('Cloud Node candidates page is out of range.');
        }

        return [
            'cloudName' => self::label($cloud['name']),
            'cloudIpv4' => $ip,
            'items' => array_slice($items, ($page - 1) * 8, 8),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Snapshot the current, independently verified Cloud and Node identities.
     * Used to build a one-use confirmation, NOT to authorize an unconfirmed write.
     *
     * @return array{mode:string,serverId:int,cloudName:string,cloudIpv4:string|null,nodeUuid:string|null,nodeName:string|null,nodeAddress:string|null,match:string,oldNodeUuid:string|null}
     */
    public function preview(int $actorId, int $serverId, ?string $nodeUuid): array
    {
        $this->access->handle($actorId);
        self::serverId($serverId);
        $old = $this->linked($actorId, $serverId);
        if ($nodeUuid === null) {
            if ($old === null) {
                throw ValidationException::withMessages(['link' => 'No Cloud Node link exists.']);
            }

            return [
                'mode' => 'unlink',
                'serverId' => $serverId,
                'cloudName' => 'سرور #'.$serverId,
                'cloudIpv4' => null,
                'nodeUuid' => null,
                'nodeName' => null,
                'nodeAddress' => null,
                'match' => 'not_applicable',
                'oldNodeUuid' => $old['nodeUuid'],
            ];
        }

        self::nodeUuid($nodeUuid);
        $cloud = $this->cloud->detail($actorId, $serverId);
        $ip = $cloud['ipv4'];
        if (! is_string($ip)) {
            throw new RuntimeException('Cloud server lacks a verified IPv4.');
        }
        $node = $this->nodes->cloudLinkNode($actorId, $nodeUuid);
        $linkedElsewhere = DB::table(self::TABLE)->where('node_uuid', $node['uuid'])
            ->where('cloud_server_id', '!=', $serverId)->exists();
        if ($linkedElsewhere) {
            throw ValidationException::withMessages(['link' => 'Node already belongs to another Cloud server.']);
        }
        if ($old !== null && $old['nodeUuid'] === $node['uuid']) {
            throw ValidationException::withMessages(['link' => 'Node is already linked to this Cloud server.']);
        }

        return [
            'mode' => 'link',
            'serverId' => $serverId,
            'cloudName' => self::label($cloud['name']),
            'cloudIpv4' => $ip,
            'nodeUuid' => $node['uuid'],
            'nodeName' => self::label($node['name']),
            'nodeAddress' => $node['address'],
            'match' => self::match($node['address'], $ip),
            'oldNodeUuid' => $old['nodeUuid'] ?? null,
        ];
    }

    /**
     * Commit a confirmed local mapping only, under a cross-worker lock and
     * database uniqueness constraints. Re-read both remote identities for
     * links; refuse stale address/IP or concurrent mapping changes.
     *
     * @param  array<string,mixed>  $preview
     */
    public function apply(int $actorId, array $preview): void
    {
        $this->access->handle($actorId);
        $serverId = $preview['serverId'] ?? null;
        $mode = $preview['mode'] ?? null;
        if (! is_int($serverId) || $serverId < 1 || ! in_array($mode, ['link', 'unlink'], true)) {
            throw new InvalidArgumentException('Invalid Cloud Node link intent.');
        }

        Cache::lock('arianet:cloud-node-links:mutation', 60)->block(2, function () use ($actorId, $serverId, $mode, $preview): void {
            $this->access->handle($actorId);

            if ($mode === 'link') {
                $uuid = $preview['nodeUuid'] ?? null;
                self::nodeUuid($uuid);
                $cloud = $this->cloud->detail($actorId, $serverId);
                $node = $this->nodes->cloudLinkNode($actorId, $uuid);
                if ($cloud['ipv4'] === null
                    || $cloud['ipv4'] !== ($preview['cloudIpv4'] ?? null)
                    || $node['address'] !== ($preview['nodeAddress'] ?? null)
                    || $node['uuid'] !== $uuid) {
                    throw ValidationException::withMessages(['link' => 'Cloud or Node changed since confirmation preview.']);
                }
            }

            DB::transaction(function () use ($actorId, $serverId, $mode, $preview): void {
                $current = DB::table(self::TABLE)->where('cloud_server_id', $serverId)
                    ->lockForUpdate()->first();
                $existing = $current?->node_uuid;
                if ($existing !== ($preview['oldNodeUuid'] ?? null)) {
                    throw ValidationException::withMessages(['link' => 'Cloud Node mapping changed. Refresh and retry.']);
                }

                if ($mode === 'unlink') {
                    if (! is_string($existing)) {
                        throw ValidationException::withMessages(['link' => 'No current mapping to remove.']);
                    }
                    DB::table(self::TABLE)->where('cloud_server_id', $serverId)
                        ->where('node_uuid', $existing)->delete();

                    return;
                }

                $uuid = $preview['nodeUuid'];
                if (DB::table(self::TABLE)->where('node_uuid', $uuid)
                    ->where('cloud_server_id', '!=', $serverId)->exists()) {
                    throw ValidationException::withMessages(['link' => 'Node is already linked elsewhere.']);
                }

                $now = now();
                if ($current === null) {
                    DB::table(self::TABLE)->insert([
                        'cloud_server_id' => $serverId,
                        'node_uuid' => $uuid,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table(self::TABLE)->where('cloud_server_id', $serverId)
                        ->where('node_uuid', $existing)->update([
                            'node_uuid' => $uuid, 'updated_by' => $actorId, 'updated_at' => $now,
                        ]);
                }
            });
        });
    }

    private static function serverId(int $serverId): void
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException('Invalid Cloud server ID.');
        }
    }

    private static function page(int $page): void
    {
        if ($page < 1 || $page > 100) {
            throw new InvalidArgumentException('Invalid Cloud page.');
        }
    }

    private static function nodeUuid(mixed $uuid): void
    {
        if (! is_string($uuid) || preg_match(
            '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',
            $uuid,
        ) !== 1) {
            throw new InvalidArgumentException('Invalid Remnawave Node UUID.');
        }
    }

    private static function label(string $text): string
    {
        $text = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text));

        return mb_substr($text, 0, 60);
    }

    private static function match(string $address, string $ipv4): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'domain';
        }

        return $address === $ipv4 ? 'match' : 'mismatch';
    }

    private static function rank(string $match): int
    {
        return match ($match) {
            'match' => 0,
            'domain' => 1,
            default => 2,
        };
    }
}
