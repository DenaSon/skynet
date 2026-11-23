<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Arianet;

use RuntimeException;

/**
 * A write may have reached Cloud even when its result could not be verified.
 * Reconcile by GET; never blindly reissue the POST.
 */
final class ArianetCloudWriteUncertain extends RuntimeException
{
}
