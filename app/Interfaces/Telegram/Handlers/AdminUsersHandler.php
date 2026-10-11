<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;

final class AdminUsersHandler
{
    public function __construct(private readonly UserManagementUi $ui) {}

    public function __invoke(Nutgram $bot): void
    {
        $this->ui->browse($bot);
    }
}
