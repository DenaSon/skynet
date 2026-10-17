<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Throwable;
use UnexpectedValueException;

/**
 * CFG-01: authorized, read-only view of existing provider profiles and inbounds.
 * No raw Xray configuration or credentials are exposed to Telegram.
 */
final readonly class ReadAdminConfigProfiles
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ManageAdminNodes $nodes,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /** @return list<array{uuid:string,name:string}> */
    public function all(int $actorId): array
    {
        $this->access->handle($actorId);

        return $this->nodes->profiles($actorId);
    }

    /**
     * A missing Node/profile relationship is unknown, not zero linked Nodes.
     *
     * @return array{uuid:string,name:string,inbounds:list<array{uuid:string,name:string}>,linkedNodes:list<string>|null}|null
     */
    public function detail(int $actorId, string $uuid): ?array
    {
        $this->access->handle($actorId);
        if (! self::uuidValid($uuid)) {
            return null;
        }

        // Recheck the current list on every callback; stale/removed IDs fail closed.
        $profiles = $this->nodes->profiles($actorId);
        $matches = array_values(array_filter($profiles, static fn (array $profile): bool => $profile['uuid'] === strtolower($uuid)));
        if (count($matches) !== 1) {
            return null;
        }

        $inbounds = $this->nodes->inbounds($actorId, $matches[0]['uuid']);
        $linked = null;

        // Optional dependency view. Older provider variants omit Node profile
        // bindings; do not claim there are no dependencies in that case.
        try {
            $remote = (new RemnawaveNodesApi($this->factory->make()))->all();
            if (count($remote) > 500) {
                throw new UnexpectedValueException('Node inventory exceeds safe view limit.');
            }
            $names = [];
            $seen = [];
            foreach ($remote as $node) {
                $nodeUuid = $node['uuid'] ?? null;
                $name = $node['name'] ?? null;
                $config = $node['configProfile'] ?? null;
                if (! self::uuidValid($nodeUuid)
                    || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 80
                    || ! is_array($config)
                    || ! self::uuidValid($config['activeConfigProfileUuid'] ?? null)
                    || isset($seen[strtolower($nodeUuid)])) {
                    throw new UnexpectedValueException('Incomplete or ambiguous Node profile binding.');
                }
                $seen[strtolower($nodeUuid)] = true;
                if (strtolower($config['activeConfigProfileUuid']) === $matches[0]['uuid']) {
                    $names[] = $name;
                }
            }
            $linked = $names;
        } catch (Throwable) {
            // Existing profile/inbound reads remain available; dependency is unknown.
        }

        return [
            'uuid' => $matches[0]['uuid'],
            'name' => $matches[0]['name'],
            'inbounds' => $inbounds,
            'linkedNodes' => $linked,
        ];
    }

    private static function uuidValid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D',
            $value,
        ) === 1;
    }
}
