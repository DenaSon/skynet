<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Arianet;

use RuntimeException;

/** Explicit provider rejection, not an ambiguous transport outcome. */
final class ArianetCloudWriteRejected extends RuntimeException {}
