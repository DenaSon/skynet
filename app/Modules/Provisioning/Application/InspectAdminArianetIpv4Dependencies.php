<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ARI-03D: bounded GET-only ownership/DNS audit before a Production IP change.
 * A clean inventory is not a write authorization or proof of client connectivity.
 */
final readonly class InspectAdminArianetIpv4Dependencies
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private PreviewAdminArianetIpv4Change $cloud,
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
        // Read the existing journal directly to avoid coupling the read-only
        // dependency audit to the action that uses it as its preflight.
        $operation = DB::table('arianet_cloud_ipv4_changes')
            ->where('cloud_server_id', $serverId)->latest('id')->first();
        $change = $operation === null ? null : [
            'status' => $operation->status,
            'oldIpv4' => $operation->old_ipv4,
            'newIpv4' => $operation->new_ipv4,
        ];
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
                'otherNodeHostCount' => 0, 'unassignedOtherCount' => 0,
                'ambiguousHostCount' => 0, 'sharedHostCount' => 0,
                'domainMatchedHostCount' => 0,
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
            $issues[] = 'آدرس نود باید دامنه مدیریت‌شده در Cloudflare باشد.';
        } else {
            $this->addDns($actorId, $nodeAddress, $oldIp, $dns, $issues, management: true);
        }
        $addresses[strtolower($nodeAddress)] = true;

        $hosts = [];
        $direct = 0;
        $targetIds = [];
        $explicitOther = 0;
        $unassignedUnrelated = 0;
        $ambiguous = 0;
        $shared = 0;
        $domainMatched = 0;
        foreach ($allHosts as $host) {
            $members = $host['nodes'];
            $assigned = in_array($uuid, $members, true);
            // A Profile/Inbound can be shared by multiple regions. It is NOT
            // evidence that the Host belongs to this Cloud server. Only
            // explicit Node membership proves assignment. An unassigned Host
            // on the selected Node's exact endpoint remains ambiguous.
            $sameEndpoint = $host['address'] === $oldIp
                || strtolower($host['address']) === strtolower($nodeAddress);
            $possibleLegacy = $members === [] && $sameEndpoint;
            // A Host without explicit Node membership may still use the
            // exact, managed Node hostname. It needs no Remnawave mutation.
            // Peer-Node/Host collisions and DNS mismatches are checked below.
            $sameNodeDomain = $members === []
                && strtolower($host['address']) === strtolower($nodeAddress)
                && filter_var($nodeAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false;

            if (! $assigned && ! $possibleLegacy) {
                if ($members === []) {
                    $unassignedUnrelated++;
                    // An unrelated endpoint is not an ownership claim even
                    // when a reusable Profile/Inbound is shared.
                } else {
                    $explicitOther++;
                }

                continue;
            }

            $targetIds[$host['uuid']] = true;
            if ($sameNodeDomain) {
                $domainMatched++;
            } elseif ($possibleLegacy) {
                $ambiguous++;
                $issues[] = 'وابستگی یکی از دامنه‌های اتصال به نود قطعی نیست؛ بررسی دستی لازم است.';
            }
            if ($assigned && count($members) !== 1) {
                $shared++;
                $issues[] = 'یکی از دامنه‌های اتصال بین چند نود مشترک است.';
            }

            $address = $host['address'];
            $type = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? 'direct_ip' : 'hostname';
            if ($type === 'direct_ip') {
                $direct++;
                $issues[] = 'اتصال مستقیم IP در محدوده نود شناسایی شد؛ تغییر خودکار DNS کافی نیست.';
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
                'unverified' => $possibleLegacy && ! $sameNodeDomain,
                'domainMatched' => $sameNodeDomain,
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
            'otherNodeHostCount' => $explicitOther,
            'unassignedOtherCount' => $unassignedUnrelated,
            'ambiguousHostCount' => $ambiguous,
            'sharedHostCount' => $shared,
            'domainMatchedHostCount' => $domainMatched,
        ];
    }

    /**
     * Inspect only one unique editable A record in a trusted Cloudflare zone.
     * Do not silently assume missing/ambiguous records are safe to transfer.
     *
     * @param  array<string,array<string,mixed>>  $dns
     * @param  list<string>  $issues
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
