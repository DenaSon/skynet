<?php

return [
    // Deployment infrastructure only. Never store SSH keys or Restic passwords here.
    'enabled' => (bool) env('RW_BACKUP_ENABLED', false),
    'host' => env('RW_BACKUP_SSH_HOST', ''),
    'port' => (int) env('RW_BACKUP_SSH_PORT', 22),
    'user' => env('RW_BACKUP_SSH_USER', 'root'),
    'key_path' => env('RW_BACKUP_SSH_KEY_PATH', ''),
    'known_hosts_path' => env('RW_BACKUP_SSH_KNOWN_HOSTS_PATH', ''),
];
