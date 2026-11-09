<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps Laravel rotation group-writable and the Runflare hook syntactically valid', function (): void {
    expect(config('logging.channels.single.permission'))->toBe(0664)
        ->and(config('logging.channels.daily.permission'))->toBe(0664)
        ->and(config('logging.channels.monthly.permission'))->toBe(0664);

    $check = new Process(['/bin/sh', '-n', base_path('resources/scripts/prepare-ssh-runtime.sh')]);
    $check->run();

    expect($check->isSuccessful())->toBeTrue();
});
