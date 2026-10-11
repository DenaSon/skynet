<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

/**
 * Provider inventory only. It is NOT a reachability test from Iran or a
 * promise that all hosts and protocol configurations are usable.
 */
final readonly class RemnawaveDiscoverySnapshot
{
    /**
     * @param  list<RemnawaveNodeSummary>  $nodes
     */
    public function __construct(
        public array $nodes,
        public int $hosts,
        public int $internalSquads,
        public int $configProfiles,
    ) {}

    public function connectedNodes(): int
    {
        return count(array_filter(
            $this->nodes,
            static fn (RemnawaveNodeSummary $node): bool => $node->connected && ! $node->disabled,
        ));
    }
}
