<?php

declare(strict_types=1);

namespace App\Support\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Named Spatie checks share a small read-only operations adapter.
 * Never include credentials, URLs, exception messages, raw job payloads or IDs
 * in stored health result metadata or short summaries.
 */
final class DenaGateOperationalCheck extends Check
{
    private string $probe = '';

    public static function makeFor(string $probe): self
    {
        $check = new self;
        $check->probe = $probe;

        return $check->name('ops_'.$probe);
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            $measurement = app(OperationalProbe::class)->inspect($this->probe);
        } catch (Throwable) {
            // A failed probe is not proof that the underlying service failed.
            return $result->warning()->shortSummary('unavailable');
        }

        $result->shortSummary($measurement['summary']);

        return match ($measurement['status']) {
            'ok' => $result->ok(),
            'failed' => $result->failed(),
            default => $result->warning(),
        };
    }
}
