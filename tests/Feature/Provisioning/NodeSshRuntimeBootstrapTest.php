<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps the Runflare SSH bootstrap POSIX-shell valid and narrowly scoped', function (): void {
    $script = base_path('resources/scripts/prepare-ssh-runtime.sh');

    $check = new Process(['sh', '-n', $script]);
    $check->mustRun();

    $content = file_get_contents($script);
    expect($content)
        ->toBeString()
        ->toContain('set -eu')
        ->toContain('command -v ssh ')
        ->toContain('command -v ssh-keyscan ')
        ->toContain('apt-get install -y -qq --no-install-recommends openssh-client')
        ->toContain('chmod 755 "$ASKPASS"')
        ->toContain('test -x "$ASKPASS"')
        ->not->toContain('sshpass')
        ->not->toContain('REMNAWAVE_WRITES_ENABLED')
        ->not->toContain('systemctl')
        ->not->toContain('docker run');
});
