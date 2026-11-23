<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use RuntimeException;
use Throwable;

/**
 * ARI-03C: bounded GET-only impact audit before any coordinated DNS/Node work.
 * A clean inventory is not a write authorization or proof of client connectivity.
 */
final readonly class InspectAdminArianetIpv4Dependencies
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private PreviewAdminArianetIpv4Change $cloud,
        private ChangeAdminArianetIpv4 $changes,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
        private ManageCloudflareDns $dns,
    ) {}

    /** @return array<string,mixed> */
    public function handle(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        if ($serverId < 1) {
            throw new RuntimeException('Invalid Cloud server identity.');
        }
        $cloud = $this->cloud->handle($actorId, $serverId);
        $change = $this->changes->status($actorId, $serverId);
        $oldIp = $cloud['ipv4'];
        $newIp = null;
        $issues = [];

        if (($change['status'] ?? null) === 'awaiting_coordination') {
            $oldIp = $change['oldIpv4'];
            $newIp = $change['newIpv4'];
            if (! is_string($newIp) || $cloud['ipv4'] !== $newIp) {
                $issues[] = 'IPv4 جدید هنوز با اطلاعات زنده سرور تأیید نشده است.';
            }
        } elseif ($change !== null && in_array($change['status'], [
            'accepted', 'reserved', 'uncertain', 'needs_review',
        ], true)) {
            $issues[] = 'عملیات تعویض هنوز نتیجه قطعی ندارد و به بررسی نیاز دارد.';
        }

        if (! is_string($oldIp) || filter_var($oldIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Old Cloud IPv4 is unavailable.');
        }

        $uuid = $cloud['nodeUuid'];
        if (! is_string($uuid)) {
            return [
                'cloudName' => $cloud['name'], 'oldIp' => $oldIp, 'newIp' => $newIp,
                'nodeName' => 'ثبت نشده', 'nodeAddress' => null,
                'hosts' => [], 'dns' => [], 'issues' => [...$issues, 'سرور هنوز به نود متصل نشده است.'],
                'ready' => false,
                'affectedHostCount' => 0, 'directHostCount' => 0,
            ];
        }

        $node = $this->nodes->replacementSnapshot($actorId, $uuid);
        $allNodes = $this->nodes->replacementAddresses($actorId);
        $allHosts = $this->hosts->replacementInventory($actorId);
        $nodeName = $node['name'];
        $nodeAddress = $node['address'];
        if ($node['disabled']) {
            $issues[] = 'نود مرتبط غیرفعال است.';
        }
        if ($node['port'] !== 2222) {
            $issues[] = 'درگاه مدیریت نود با تنظیم مورد انتظار مطابقت ندارد.';
        }

        $dns = [];
        $addresses = [];
        if (filter_var($nodeAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($nodeAddress !== $oldIp) {
                $issues[] = 'آدرس مستقیم نود با IPv4 قبلی سرور یکسان نیست.';
            }
        } else {
            $this->addDns($actorId, $nodeAddress, $oldIp, $dns, $issues, management: true);
        }
        $addresses[strtolower($nodeAddress)] = true;

        $hosts = [];
        $direct = 0;
        $targetIds = [];
        foreach ($allHosts as $host) {
            $members = $host['nodes'];
            $assigned = in_array($uuid, $members, true);
            $possibleLegacy = $members === []
                && ($host['address'] === $oldIp
                    || strtolower($host['address']) === strtolower($nodeAddress)
                    || ($host['profileUuid'] === $node['profileUuid']
                        && in_array($host['inboundUuid'], $node['inboundUuids'], true)));

            if (! $assigned && ! $possibleLegacy) {
                continue;
            }

            $targetIds[$host['uuid']] = true;
            if ($possibleLegacy) {
                $issues[] = 'وابستگی یکی از دامنه‌های اتصال به نود قطعی نیست؛ بررسی دستی لازم است.';
            }
            if ($assigned && count($members) !== 1) {
                $issues[] = 'یکی از دامنه‌های اتصال بین چند نود مشترک است.';
            }

            $address = $host['address'];
            $type = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? 'direct_ip' : 'hostname';
            if ($type === 'direct_ip') {
                $direct++;
                if ($address !== $oldIp) {
                    $issues[] = 'یک آدرس مستقیم اتصال با IPv4 قبلی سرور متفاوت است.';
                }
            } else {
                $this->addDns($actorId, $address, $oldIp, $dns, $issues);
            }
            $addresses[strtolower($address)] = true;
            $hosts[] = [
                'uuid' => $host['uuid'],
                'name' => $host['remark'],
                'address' => $address,
                'type' => $type,
                'shared' => count($members) > 1,
                'unverified' => $possibleLegacy,
            ];
        }

        // Never assume an unassigned Host or another Node belongs to this server.
        // Report collisions with known target endpoints instead of modifying them.
        foreach ($allNodes as $other) {
            if ($other['uuid'] === $uuid) {
                continue;
            }
            if ($other['address'] === $oldIp
                || isset($addresses[strtolower($other['address'])])
                || ($newIp !== null && $other['address'] === $newIp)) {
                $issues[] = 'یک نود دیگر از آدرس وابسته به این سرور استفاده می‌کند.';
            }
        }
        foreach ($allHosts as $host) {
            if (isset($targetIds[$host['uuid']])) {
                continue;
            }
            if ($host['address'] === $oldIp || isset($addresses[strtolower($host['address'])])
                || ($newIp !== null && $host['address'] === $newIp)) {
                $issues[] = 'یک دامنه اتصال دیگر از آدرس مشترک استفاده می‌کند.';
            }
        }

        if (count($hosts) > 10 || count($dns) > 10 || $direct > 10) {
            $issues[] = 'تعداد وابستگی‌ها از محدوده بررسی امن فراتر است.';
        }
        if ($hosts === []) {
            $issues[] = 'دامنه اتصال مرتبط با این نود شناسایی نشد؛ عضویت و تنظیمات را بررسی کنید.';
        }

        return [
            'cloudName' => $cloud['name'],
            'oldIp' => $oldIp,
            'newIp' => $newIp,
            'nodeName' => $nodeName,
            'nodeAddress' => $nodeAddress,
            'hosts' => array_slice($hosts, 0, 10),
            'dns' => array_values(array_slice($dns, 0, 10)),
            'issues' => array_values(array_unique($issues)),
            'ready' => $issues === [],
            'affectedHostCount' => count($hosts),
            'directHostCount' => $direct,
        ];
    }

    /**
     * Inspect only one unique editable A record in a trusted Cloudflare zone.
     * Do not silently assume missing/ambiguous records are safe to transfer.
     *
     * @param array<string,array<string,mixed>> $dns
     * @param list<string> $issues
     */
    private function addDns(
        int $actorId,
        string $hostname,
        string $oldIp,
        array &$dns,
        array &$issues,
        bool $management = false,
    ): void {
        $key = strtolower($hostname);
        if (isset($dns[$key])) {
            return;
        }
        try {
            $snapshot = $this->dns->replacementRecord($actorId, $hostname);
            $record = $snapshot['record'];
            if ($record['content'] !== $oldIp) {
                $issues[] = 'یک رکورد DNS به IPv4 قبلی سرور اشاره نمی‌کند.';
            }
            if ($management && $record['proxied'] !== false) {
                $issues[] = 'رکورد مدیریت نود باید در حالت DNS Only باشد.';
            }
            $dns[$key] = [
                'hostname' => $record['name'],
                'ipv4' => $record['content'],
                'proxied' => $record['proxied'],
                'matched' => $record['content'] === $oldIp,
            ];
        } catch (Throwable) {
            $issues[] = 'یکی از رکوردهای DNS قابل بررسی یا دارای مالکیت قطعی نیست.';
        }
    }
}
