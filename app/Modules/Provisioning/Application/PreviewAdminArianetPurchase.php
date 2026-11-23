<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * ARI-04A: fresh GET-only quote, with no Cloud creation or Node changes.
 * Read-only selections are always revalidated against the provider.
 */
final readonly class PreviewAdminArianetPurchase
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ArianetCloudReadClient $cloud,
    ) {}

    /** @return list<array{code:string,name:string}> */
    public function datacenters(int $actorId): array
    {
        $this->access->handle($actorId);
        $raw = $this->cloud->datacenters()['data'] ?? null;
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > 30) {
            throw new RuntimeException('Cloud datacenter catalog cannot be verified.');
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null)
                || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $row['code']) !== 1) {
                throw new RuntimeException('Invalid Cloud datacenter.');
            }
            if (($row['orderable'] ?? null) === true) {
                $out[] = [
                    'code' => $row['code'],
                    'name' => self::label($row['name'] ?? $row['code']),
                ];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function plan(int $actorId, string $datacenter, int $planId): array
    {
        $this->access->handle($actorId);
        if ($planId < 1 || ! in_array($datacenter, array_column($this->datacenters($actorId), 'code'), true)) {
            throw new InvalidArgumentException('Datacenter or plan is not orderable.');
        }
        $rows = $this->cloud->plans($datacenter)['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 200) {
            throw new RuntimeException('Cloud plan catalog cannot be verified.');
        }
        $matches = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $planId
                && ($row['datacenter'] ?? null) === $datacenter));
        if (count($matches) !== 1) {
            throw new RuntimeException('Selected plan is not available.');
        }
        $row = $matches[0];
        if (($row['is_active'] ?? null) !== true
            || ($row['availability']['available'] ?? null) !== true
            || ($row['currency'] ?? null) !== 'IRR'
            || ! self::positive($row['vcpu'] ?? null)
            || ! self::positive($row['ram_mb'] ?? null)
            || ! self::positive($row['disk_gb'] ?? null)
            || ! self::positive($row['minimum_usage_hours'] ?? null)
            || ! self::positive($row['hourly_price'] ?? null)
            || ! self::positive($row['monthly_price'] ?? null)) {
            throw new RuntimeException('Plan availability or IRR pricing cannot be verified.');
        }

        return [
            'id' => $planId,
            'datacenter' => $datacenter,
            'name' => self::label($row['name'] ?? null),
            'cores' => $row['vcpu'],
            'memoryMb' => $row['ram_mb'],
            'diskGb' => $row['disk_gb'],
            'trafficGb' => is_int($row['traffic_gb'] ?? null) && $row['traffic_gb'] >= 0
                ? $row['traffic_gb'] : null,
            'hourlyRials' => $row['hourly_price'],
            'monthlyRials' => $row['monthly_price'],
            'minimumHours' => $row['minimum_usage_hours'],
        ];
    }

    /** @return list<array{image:string,name:string}> */
    public function images(int $actorId, string $datacenter, int $planId): array
    {
        $plan = $this->plan($actorId, $datacenter, $planId);
        $result = $this->cloud->systemImages($datacenter);
        $rows = $result['data'] ?? null;
        $page = $result['meta']['pagination'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100
            || (is_array($page) && ($page['last_page'] ?? 1) !== 1)) {
            throw new RuntimeException('System image catalog cannot be verified.');
        }

        $images = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['type'] ?? null) !== 'system'
                || ($row['status'] ?? null) !== 'available'
                || ($row['deprecated'] ?? null) !== false
                || ! is_array($row['datacenters'] ?? null)
                || ! in_array($datacenter, $row['datacenters'], true)
                || ! is_int($row['min_disk_gb'] ?? null)
                || $row['min_disk_gb'] > $plan['diskGb']) {
                continue;
            }
            $image = $row['image'] ?? null;
            if (! is_string($image) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $image) !== 1
                || (! str_starts_with($image, 'ubuntu-') && ! str_starts_with($image, 'debian-'))) {
                continue;
            }
            $images[$image] = [
                'image' => $image,
                'name' => self::label($row['name'] ?? $image),
            ];
        }

        return array_values($images);
    }

    /**
     * @return array{items:list<array{id:int,name:string}>,page:int,pages:int}
     */
    public function sshKeys(int $actorId, int $page = 1): array
    {
        $this->access->handle($actorId);
        $result = $this->cloud->sshKeys($page);
        $rows = $result['data'] ?? null;
        $pagination = $result['pagination'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 8
            || ! is_array($pagination)
            || ($pagination['current_page'] ?? null) !== $page
            || ! is_int($pagination['last_page'] ?? null)
            || $pagination['last_page'] < $page || $pagination['last_page'] > 100) {
            throw new RuntimeException('SSH key list cannot be verified.');
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! self::positive($row['id'] ?? null)) {
                throw new RuntimeException('Invalid SSH key list entry.');
            }
            $items[] = [
                'id' => $row['id'],
                'name' => self::label($row['name'] ?? null),
            ];
        }

        return ['items' => $items, 'page' => $page, 'pages' => $pagination['last_page']];
    }

    /** @return array<string,mixed> */
    public function quote(
        int $actorId,
        string $datacenter,
        int $planId,
        string $image,
        string $name,
        int $sshKeyId,
    ): array {
        $this->access->handle($actorId);
        if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/D', $name) !== 1
            || $sshKeyId < 1) {
            throw new InvalidArgumentException('Invalid server name or SSH key.');
        }
        $plan = $this->plan($actorId, $datacenter, $planId);
        if (! in_array($image, array_column($this->images($actorId, $datacenter, $planId), 'image'), true)) {
            throw new RuntimeException('Selected operating system is not available.');
        }
        $key = $this->cloud->sshKey($sshKeyId)['data'] ?? null;
        if (! is_array($key) || ($key['id'] ?? null) !== $sshKeyId
            || ! is_string($key['key'] ?? null)
            || preg_match('/^ssh-(?:ed25519|rsa) [A-Za-z0-9+\/=]+(?:\s|$)/', $key['key']) !== 1) {
            throw new RuntimeException('Existing public SSH key is not verified.');
        }

        return [
            'name' => $name,
            'plan' => $plan,
            'image' => $image,
            'sshKeyId' => $sshKeyId,
            'sshKeyName' => self::label($key['name'] ?? null),
            // Bind a paid confirmation to the exact public key bytes, not only
            // an account key ID/name that may be changed after the preview.
            'sshPublicKeyHash' => hash('sha256', trim($key['key'])),
            'minimumRials' => $plan['hourlyRials'] * $plan['minimumHours'],
        ];
    }

    private static function positive(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function label(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'نامشخص';
        }

        return mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value)), 0, 50);
    }
}
