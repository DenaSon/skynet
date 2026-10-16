<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Presentation\Console;

use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use Illuminate\Console\Command;
use Throwable;

/** Explicit one-order staging operator action. No scheduled or Telegram invocation. */
final class FulfillPaidPlanOrderCommand extends Command
{
    protected $signature = 'sales:fulfillment:run {order : Existing paid managed-plan order ID} {--reconcile : GET-only provider reconciliation, never another CREATE}';

    protected $description = 'Execute or reconcile one paid plan order through the guarded fulfillment outbox';

    public function handle(FulfillPaidPlanOrder $orders): int
    {
        $id = filter_var($this->argument('order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Invalid order identifier.');

            return self::FAILURE;
        }

        try {
            $remoteId = $this->option('reconcile')
                ? $orders->reconcile($id)
                : $orders->handle($id);

            $this->info('Order provisioning confirmed. Remote ID: '.$remoteId);

            return self::SUCCESS;
        } catch (Throwable) {
            // Never print provider response bodies, URLs, bearer tokens or bank details.
            $this->error('Order cannot be completed safely; inspect the database state and reconcile explicitly.');

            return self::FAILURE;
        }
    }
}
