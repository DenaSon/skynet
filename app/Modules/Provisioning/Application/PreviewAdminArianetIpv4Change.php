<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use InvalidArgumentException;
use Throwable;

/**
 * ARI-03A: live, read-only IPv4-change preview. This projection never creates
 * a write intent, invokes a provider mutation or declares execution safe.
 */
final readonly class PreviewAdminArianetIpv4Change
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminArianetCloud $cloud,
        private ManageAdminArianetCloudLinks $links,
        private ManageAdminNodes $nodes,
        private ManageCloudflareDns $dns,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        if ($serverId < 1) {
            throw new InvalidArgumentException('Invalid Cloud server ID.');
        }

        // Always fetch a fresh Cloud snapshot; no cached fee, allowance or IP.
        $server = $this->cloud->detail($actorId, $serverId);
        $link = $this->links->linked($actorId, $serverId);
        $node = null;
        $nodeState = $link === null ? 'unlinked' : 'unavailable';
        $dns = ['state' => 'not_applicable', 'name' => null, 'ipv4' => null, 'proxied' => null];

        if ($link !== null) {
            try {
                $node = $this->nodes->cloudLinkNode($actorId, $link['nodeUuid']);
                if (filter_var($node['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $nodeState = $server['ipv4'] === $node['address'] ? 'ip_match' : 'ip_mismatch';
                } elseif (filter_var($node['address'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                    $nodeState = 'hostname';
                    // Only inspect one unique A record for the Node management
                    // hostname in a configured/allowed Cloudflare zone. The
                    // customer-facing Host domains can be different and are NOT
                    // exhaustively discovered by this preview.
                    $dns['state'] = 'unverified';
                    try {
                        $snapshot = $this->dns->replacementRecord($actorId, $node['address']);
                        $record = $snapshot['record'];
                        $dns = [
                            'state' => $server['ipv4'] !== null && $record['content'] === $server['ipv4']
                                ? 'match' : 'mismatch',
                            'name' => $record['name'],
                            'ipv4' => $record['content'],
                            'proxied' => $record['proxied'],
                        ];
                    } catch (Throwable) {
                        // No DNS response, unapproved zone or competing record:
                        // require manual review, never pretend there is no DNS.
                    }
                } else {
                    $nodeState = 'unavailable';
                }
            } catch (Throwable) {
                // The locally saved UUID is still presented; an unavailable RW
                // read must never be mistaken for a missing Node or DNS record.
            }
        }

        return [
            'serverId' => $serverId,
            'name' => $server['name'],
            'status' => $server['status'],
            'ipv4' => $server['ipv4'],
            'changeAllowed' => $server['changeAllowed'],
            'freeLeft' => $server['freeLeft'],
            'changeUsed' => $server['changeUsed'],
            'pending' => $server['pending'],
            'feeRials' => $server['changeFeeRials'],
            'nodeUuid' => $link['nodeUuid'] ?? null,
            'node' => $node,
            'nodeState' => $nodeState,
            'dns' => $dns,
        ];
    }
}
