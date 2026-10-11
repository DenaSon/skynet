<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Presentation\Console;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Illuminate\Console\Command;
use Throwable;

/**
 * Explicit operator-only smoke check. It never writes to the panel or
 * reveals API credentials, node addresses or protocol configuration.
 */
final class InspectRemnawave extends Command
{
    protected $signature = 'remnawave:inspect {--nodes : Show node names and connection flags}';

    protected $description = 'Read-only check of configured Remnawave panel and resource inventory';

    public function handle(RemnawaveApiClientFactory $factory): int
    {
        try {
            $api = new RemnawaveReadApi($factory->make());
            $instances = $api->healthInstanceCount();
            $inventory = $api->inventory();
        } catch (Throwable $exception) {
            // Never print exception messages: HTTP errors may expose URLs and headers.
            $this->error('Remnawave inspection failed. Check configuration, token scopes and connectivity.');

            return self::FAILURE;
        }

        $this->info('Remnawave API inspection successful (read-only).');
        $this->line('Runtime instances: '.$instances);
        $this->line('Nodes: '.count($inventory->nodes).' (connected and enabled: '.$inventory->connectedNodes().')');
        $this->line('Hosts: '.$inventory->hosts);
        $this->line('Internal squads: '.$inventory->internalSquads);
        $this->line('Config profiles: '.$inventory->configProfiles);

        if ($this->option('nodes')) {
            foreach ($inventory->nodes as $node) {
                $this->line(sprintf(
                    'Node %s (%s): %s%s',
                    $node->name,
                    $node->uuid,
                    $node->connected ? 'connected' : 'disconnected',
                    $node->disabled ? ', disabled' : '',
                ));
            }
        }

        return self::SUCCESS;
    }
}
