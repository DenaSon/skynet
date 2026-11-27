<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\NodeSetup;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * NW-01: SSH identity and password preflight only. No mutation on the VPS.
 */
final class NodeSshPreflight
{
    private const NODE_KEY = '/run/secrets/denagate-node-ssh';

    /** Only the prepared, root-owned PHP-FPM runtime credential is eligible. */
    public function keyAvailable(): bool
    {
        $path = self::NODE_KEY;
        $mode = @fileperms($path);

        return is_file($path) && ! is_link($path) && is_readable($path)
            && @fileowner($path) === 0 && is_int($mode)
            && ($mode & 0077) === 0040;
    }

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
     * Uses OpenSSH strict host-key checking and exactly one authentication
     * method. A private key is never placed in Telegram, cache or argv.
     */
    public function check(
        string $ip,
        int $port,
        string $username,
        string $password,
        string $knownHost,
        string $authMode = 'password',
    ): string {
        $output = $this->runSsh(
            $ip,
            $port,
            $username,
            $password,
            $knownHost,
            $authMode,
            'uname -s; . /etc/os-release; printf "ID=%s\n" "$ID"',
            12,
        );
        if (! str_contains($output, 'Linux') || ! preg_match('/\bID=(ubuntu|debian)\b/', $output)) {
            throw new RuntimeException('Unsupported VPS OS.');
        }

        return str_contains($output, 'ID=ubuntu') ? 'Ubuntu' : 'Debian';
    }

    /**
     * Basic, read-only inspection for a preinstalled Remnawave Node.
     * Deliberately does not probe management or client-facing ports: those
     * become available as part of the panel activation/cutover sequence.
     *
     * @return array{status:string,image:string,os:string}
     */
    public function inspectReadyNode(
        string $ip,
        int $port,
        string $username,
        string $password,
        string $knownHost,
        string $authMode = 'password',
    ): array {
        if ($username !== 'root') {
            throw new RuntimeException('Root SSH access is required to inspect the Node container.');
        }

        $output = $this->runSsh(
            $ip,
            $port,
            $username,
            $password,
            $knownHost,
            $authMode,
            <<<'SH'
set -eu
[ "$(uname -s)" = "Linux" ] || exit 12
command -v docker >/dev/null 2>&1 || exit 10
docker info >/dev/null 2>&1 || exit 11
printf '%s|' "$(uname -s)"
docker inspect --format '{{.State.Status}}|{{.State.Running}}|{{.Config.Image}}' remnanode
SH,
            15,
        );

        $parts = explode('|', trim($output), 4);
        if (count($parts) !== 4
            || $parts[0] !== 'Linux'
            || $parts[1] !== 'running'
            || $parts[2] !== 'true'
            || preg_match('/\Aremnawave\/node(?::[A-Za-z0-9._-]+)?\z/D', $parts[3]) !== 1) {
            throw new RuntimeException('A running Remnawave Node container was not verified.');
        }

        return ['status' => 'running', 'image' => $parts[3], 'os' => $parts[0]];
    }

    private function runSsh(
        string $ip,
        int $port,
        string $username,
        string $password,
        string $knownHost,
        string $authMode,
        string $command,
        int $timeout,
    ): string {
        $this->validateTarget($ip, $port);
        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/iD', $username) !== 1
            || ! str_starts_with($knownHost, ($port === 22 ? $ip : '['.$ip.']:'.$port).' ssh-ed25519 ')
            || preg_match('/[\r\n]/', $knownHost) === 1
            || ! in_array($authMode, ['password', 'key'], true)
            || ($authMode === 'password' && ($password === '' || strlen($password) > 256))
            || ($authMode === 'key' && ($username !== 'root' || $password !== '' || ! $this->keyAvailable()))) {
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

            $method = $authMode === 'key'
                ? [
                    '-o', 'PreferredAuthentications=publickey',
                    '-o', 'PubkeyAuthentication=yes',
                    '-o', 'PasswordAuthentication=no',
                    '-o', 'BatchMode=yes',
                    '-o', 'IdentitiesOnly=yes',
                    '-o', 'IdentityAgent=none',
                    '-i', self::NODE_KEY,
                ]
                : [
                    '-o', 'PreferredAuthentications=password',
                    '-o', 'PubkeyAuthentication=no',
                    '-o', 'KbdInteractiveAuthentication=no',
                    '-o', 'NumberOfPasswordPrompts=1',
                ];
            $environment = $authMode === 'key' ? [] : [
                'SSH_ASKPASS' => base_path('resources/scripts/nw01-askpass.sh'),
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'nw01:0',
                'PARSAGATE_SSH_PASSWORD' => $password,
            ];
            $process = new Process([
                'ssh', '-F', '/dev/null',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$knownHostsPath,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                ...$method,
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'ConnectTimeout=5',
                '-o', 'ConnectionAttempts=1',
                '-o', 'LogLevel=ERROR',
                '-p', (string) $port,
                $username.'@'.$ip,
                'sh -s',
            ], base_path(), $environment);
            $process->setInput($command."\n");
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 2048) {
                throw new RuntimeException('SSH preflight failed.');
            }

            return $process->getOutput();
        } catch (Throwable) {
            // Do not expose remote stderr or output; it may contain credentials.
            throw new RuntimeException('SSH preflight failed.');
        } finally {
            @unlink($knownHostsPath);
        }
    }
}
