<?php

declare(strict_types=1);

it('pins transactional InnoDB engine for MySQL and MariaDB migrations', function (): void {
    // Financial ledger, payment outbox and Sales/Provisioning FK tables cannot
    // safely be created under a MyISAM or Aria server default.
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB')
        ->and(config('database.connections.mariadb.engine'))->toBe('InnoDB');
});
