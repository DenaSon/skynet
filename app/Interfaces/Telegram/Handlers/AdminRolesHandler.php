<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\RbacUi;
use SergiX44\Nutgram\Nutgram;

final class AdminRolesHandler
{
    public function __construct(private readonly RbacUi $ui) {}

    public function __invoke(Nutgram $bot): void
    {
        $this->ui->browse($bot);
    }
}
