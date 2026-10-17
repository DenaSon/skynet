<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\NodeSetup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * NW-01: SSH identity and password preflight only. No mutation on the VPS.
 */
final class NodeSshPreflight
{
    public function validateTarget(string $ip, int $port): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid public VPS address or SSH port.');
        }
    }

    /** @return array{fingerprint:string,knownHost:string} */
    public function scan(string $ip, int $port): array
    {
        $this->validateTarget($ip, $port);
        $process = new Process(['ssh-keyscan', '-T', '4', '-t', 'ed25519', '-p', (string) $port, $ip]);
        $process->setTimeout(8);
        $process->run();
        foreach (explode("\n", $process->getOutput()) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (! is_array($fields) || count($fields) !== 3 || $fields[1] !== 'ssh-ed25519') {
                continue;
            }
            $binary = base64_decode($fields[2], true);
            if ($binary === false || strlen($binary) < 32) {
                continue;
            }

            return [
                'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $binary, true)), '='),
                'knownHost' => trim($line),
            ];
        }
        throw new RuntimeException('SSH host identity unavailable.');
    }

    /**
     * Uses OpenSSH strict host-key checking and the child-only askpass environment.
     * Password is never supplied as a process argument or written to a file.
     */
    public function check(string $ip, int $port, string $username, string $password, string $knownHost): string
    {
        $this->validateTarget($ip, $port);
        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/iD', $username) !== 1
            || $password === '' || strlen($password) > 256
            || ! str_starts_with($knownHost, ($port === 22 ? $ip : '['.$ip.']:'.$port).' ssh-ed25519 ')) {
            throw new RuntimeException('Invalid SSH configuration.');
        }
        $knownHostsPath = tempnam(sys_get_temp_dir(), 'nw01-');
        if ($knownHostsPath === false) {
            throw new RuntimeException('Host verification unavailable.');
        }
        try {
            chmod($knownHostsPath, 0600);
            if (file_put_contents($knownHostsPath, $knownHost."\n") === false) {
                throw new RuntimeException('Host verification unavailable.');
            }
            $process = new Process([
                'ssh', '-F', '/dev/null',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$knownHostsPath,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'PreferredAuthentications=password',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'ConnectTimeout=5',
                '-o', 'ConnectionAttempts=1',
                '-o', 'LogLevel=ERROR',
                '-p', (string) $port,
                $username.'@'.$ip,
                'uname -s; . /etc/os-release; printf "ID=%s\n" "$ID"',
            ], base_path(), [
                'SSH_ASKPASS' => base_path('resources/scripts/nw01-askpass.sh'),
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'nw01:0',
                'PARSAGATE_SSH_PASSWORD' => $password,
            ]);
            $process->setTimeout(12);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('SSH preflight failed.');
            }
            $output = $process->getOutput();
            if (! str_contains($output, 'Linux') || ! preg_match('/\bID=(ubuntu|debian)\b/', $output)) {
                throw new RuntimeException('Unsupported VPS OS.');
            }

            return str_contains($output, 'ID=ubuntu') ? 'Ubuntu' : 'Debian';
        } finally {
            @unlink($knownHostsPath);
        }
    }
}
