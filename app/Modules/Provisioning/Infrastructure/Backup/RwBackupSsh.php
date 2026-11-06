<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

final class RwBackupSsh
{
    public static function validId(string $id): bool
    {
        return preg_match('/\A(?:rw-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}|denagate-rw-[0-9]{8}T[0-9]{6}Z)\z/D', $id) === 1;
    }

    /** @return list<array{id:string,state:string,size:int,timestamp:int}> */
    public function all(): array
    {
        $entries = [];
        foreach (explode("\n", trim($this->execute('list'))) as $line) {
            if ($line === '') {
                continue;
            }
            $columns = explode("\t", $line);
            if (count($columns) !== 4
                || ! self::validId($columns[0])
                || ! in_array($columns[1], ['ready', 'running', 'failed'], true)
                || ! ctype_digit($columns[2]) || ! ctype_digit($columns[3])) {
                throw new RuntimeException('Unexpected backup index.');
            }
            $entries[] = [
                'id' => $columns[0],
                'state' => $columns[1],
                'size' => (int) $columns[2],
                'timestamp' => (int) $columns[3],
            ];
        }
        usort($entries, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $entries;
    }

    public function start(): string
    {
        $id = trim($this->execute('start', 20));
        if (! self::validId($id) || ! str_starts_with($id, 'rw-')) {
            throw new RuntimeException('Backup creation not confirmed.');
        }

        return $id;
    }

    /** @return array{size:int,sha256:string} */
    public function info(string $id): array
    {
        $fields = explode("\t", trim($this->execute('info '.$this->assertId($id))));
        if (count($fields) !== 2 || ! ctype_digit($fields[0])
            || preg_match('/\A[a-f0-9]{64}\z/D', $fields[1]) !== 1) {
            throw new RuntimeException('Invalid backup metadata.');
        }

        return ['size' => (int) $fields[0], 'sha256' => $fields[1]];
    }

    public function delete(string $id): void
    {
        if (trim($this->execute('delete '.$this->assertId($id))) !== 'deleted') {
            throw new RuntimeException('Backup deletion not confirmed.');
        }
    }

    /** Stream through a child SSH process; do not buffer a large archive in PHP memory. */
    public function stream(string $id): void
    {
        $command = $this->command('stream '.$this->assertId($id));
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new RuntimeException('Backup stream unavailable.');
        }

        fclose($pipes[0]);
        try {
            while (! feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false) {
                    throw new RuntimeException('Backup stream interrupted.');
                }
                echo $chunk;
                flush();
            }
        } finally {
            fclose($pipes[1]);
            $exit = proc_close($process);
        }

        if ($exit !== 0) {
            // The HTTP response may already be streaming; never emit SSH stderr.
            throw new RuntimeException('Backup stream interrupted.');
        }
    }

    private function assertId(string $id): string
    {
        if (! self::validId($id)) {
            throw new RuntimeException('Unknown backup identifier.');
        }

        return $id;
    }

    private function execute(string $verb, int $timeout = 15): string
    {
        $process = new Process($this->command($verb));
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Throwable) {
            throw new RuntimeException('Backup server unavailable.');
        }
        if (! $process->isSuccessful() || strlen($process->getOutput()) > 65536) {
            // Never include remote stdout/stderr: it may expose operational secrets.
            throw new RuntimeException('Backup operation unavailable or denied.');
        }

        return $process->getOutput();
    }

    /** @return list<string> */
    private function command(string $verb): array
    {
        if (config('rw_backup.enabled') !== true) {
            throw new RuntimeException('Backups are disabled.');
        }

        $host = (string) config('rw_backup.host');
        $user = (string) config('rw_backup.user');
        $port = (int) config('rw_backup.port');
        $key = (string) config('rw_backup.key_path');
        $hosts = (string) config('rw_backup.known_hosts_path');

        if (! filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/D', $host) !== 1) {
            throw new RuntimeException('Invalid backup server.');
        }
        if (preg_match('/\A[a-z_][a-z0-9_-]*\z/iD', $user) !== 1
            || $port < 1 || $port > 65535
            || ! is_file($key) || ! is_readable($key)
            || ! is_file($hosts) || ! is_readable($hosts)) {
            throw new RuntimeException('Backup SSH credentials not configured.');
        }

        return [
            'ssh', '-T', '-F', '/dev/null',
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$hosts,
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectionAttempts=1',
            '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=10',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'LogLevel=ERROR',
            '-i', $key, '-p', (string) $port,
            $user.'@'.$host, 'rw-backup '.$verb,
        ];
    }
}
