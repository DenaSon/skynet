<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\NodeSetup;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * NW-02: start a fixed systemd installer on a trusted, empty VPS; inspect its status.
 * Never execute commands supplied by Telegram; never replace an existing Node.
 */
class NodeSshInstaller
{
    public function __construct(private NodeSshPreflight $preflight) {}

    public function start(string $ip, int $port, string $user, string $password, string $knownHost, string $secret): void
    {
        if ($user !== 'root' || preg_match('/\A[A-Za-z0-9+\/=_-]{32,8192}\z/D', $secret) !== 1) {
            throw new RuntimeException('Unsupported installer credentials or Node key.');
        }
        $installer = file_get_contents(base_path('resources/scripts/nw02-install-remote.sh'));
        if (! is_string($installer) || $installer === '') {
            throw new RuntimeException('Installer script unavailable.');
        }

        // The secret is base64-encoded only for safe shell transport, NOT for encryption.
        // SSH transports the script via stdin; it never appears in argv or output.
        $secretEnv = base64_encode('SECRET_KEY='.$secret."\nNODE_PORT=2222\n");
        $compose = "services:\n  remnanode:\n    container_name: remnanode\n    hostname: remnanode\n"
            ."    image: remnawave/node:3.4.2\n    restart: always\n    network_mode: host\n"
            ."    cap_add:\n      - NET_ADMIN\n    env_file:\n      - .env\n";
        $payload = "set -eu\numask 077\n"
            ."[ \"$(id -u)\" -eq 0 ]\n"
            ."command -v systemd-run >/dev/null\n"
            ."test ! -e /opt/parsa-node-install/status\n"
            ."test ! -e /opt/remnanode/docker-compose.yml\n"
            ."test ! -e /opt/remnanode/.env\n"
            ."if command -v docker >/dev/null 2>&1; then ! docker container inspect remnanode >/dev/null 2>&1; fi\n"
            ."install -d -m 0700 /opt/parsa-node-install /opt/remnanode\n"
            ."cat > /opt/parsa-node-install/install.sh <<'NW02_INSTALL'\n".$installer."\nNW02_INSTALL\n"
            ."chmod 0700 /opt/parsa-node-install/install.sh\n"
            ."printf '%s' '".$secretEnv."' | base64 -d > /opt/remnanode/.env\n"
            ."chmod 0600 /opt/remnanode/.env\n"
            ."cat > /opt/remnanode/docker-compose.yml <<'NW02_COMPOSE'\n".$compose."NW02_COMPOSE\n"
            ."chmod 0600 /opt/remnanode/docker-compose.yml\n"
            ."printf 'pending\\n' > /opt/parsa-node-install/status\n"
            .'systemd-run --no-block --collect --unit=parsa-node-bootstrap '
            ."/bin/sh /opt/parsa-node-install/install.sh >/dev/null\n"
            ."printf 'STARTED\\n'\n";
        $reply = trim($this->ssh($ip, $port, $user, $password, $knownHost, $payload, 35));
        if ($reply !== 'STARTED') {
            throw new RuntimeException('Remote install launch not confirmed.');
        }
    }

    public function status(string $ip, int $port, string $user, string $password, string $knownHost): string
    {
        $shell = "set -eu\n"
            ."test -f /opt/parsa-node-install/status\n"
            ."cat /opt/parsa-node-install/status\n";
        $result = trim($this->ssh($ip, $port, $user, $password, $knownHost, $shell, 15));

        return in_array($result, ['pending', 'installing', 'starting', 'running', 'failed'], true)
            ? $result
            : 'unknown';
    }

    private function ssh(
        string $ip,
        int $port,
        string $user,
        string $password,
        string $knownHost,
        string $stdin,
        int $timeout,
    ): string {
        $this->preflight->validateTarget($ip, $port);
        if ($user !== 'root' || $password === '' || strlen($password) > 256
            || ! str_starts_with($knownHost, ($port === 22 ? $ip : '['.$ip.']:'.$port).' ssh-ed25519 ')) {
            throw new RuntimeException('Untrusted SSH installation target.');
        }
        $knownHosts = tempnam(sys_get_temp_dir(), 'nw02-');
        if ($knownHosts === false) {
            throw new RuntimeException('Could not pin SSH identity.');
        }

        try {
            chmod($knownHosts, 0600);
            if (file_put_contents($knownHosts, $knownHost."\n") === false) {
                throw new RuntimeException('Could not pin SSH identity.');
            }
            $process = new Process([
                'ssh', '-T', '-F', '/dev/null',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$knownHosts,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'PreferredAuthentications=password',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'ConnectTimeout=5',
                '-o', 'ConnectionAttempts=1',
                '-o', 'LogLevel=ERROR',
                '-p', (string) $port, $user.'@'.$ip,
                'sh -s',
            ], base_path(), [
                'SSH_ASKPASS' => base_path('resources/scripts/nw01-askpass.sh'),
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'nw02:0',
                'PARSAGATE_SSH_PASSWORD' => $password,
            ]);
            $process->setInput($stdin);
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 128) {
                throw new RuntimeException('Remote SSH operation failed or outcome uncertain.');
            }

            return $process->getOutput();
        } catch (Throwable) {
            // Do not include remote stderr/exception details: they can contain secrets.
            throw new RuntimeException('Remote SSH operation failed or outcome uncertain.');
        } finally {
            @unlink($knownHosts);
        }
    }
}
