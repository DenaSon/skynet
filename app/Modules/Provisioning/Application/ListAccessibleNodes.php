<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use UnexpectedValueException;

final readonly class ListAccessibleNodes
{
    public function __construct(
        private ActiveSubscriptionAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /** @return list<AccessibleNodeSummary> */
    public function handle(SubscriptionViewer $viewer, int $orderId): array
    {
        return $this->access->read($viewer, $orderId, function (RemnawaveUserSnapshot $remote): array {
            $data = (new RemnawaveUsersApi($this->factory->make()))->accessibleNodes($remote->id);
            $nodes = $data['activeNodes'] ?? null;

            if (! is_int($data['userId'] ?? null) || $data['userId'] !== $remote->id
                || ! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > 200) {
                throw new UnexpectedValueException('Invalid provider accessible nodes response.');
            }

            $safe = [];

            foreach ($nodes as $node) {
                if (! is_array($node)
                    || ! is_string($node['nodeName'] ?? null)
                    || ($name = trim($node['nodeName'])) === ''
                    || mb_strlen($name) > 100
                    || ! is_string($node['countryCode'] ?? null)
                    || ! preg_match('/^[A-Z]{2}$/D', $node['countryCode'])) {
                    throw new UnexpectedValueException('Invalid provider accessible node.');
                }

                $safe[] = new AccessibleNodeSummary($name, $node['countryCode']);
            }

            return $safe;
        });
    }
}
