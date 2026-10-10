<?php

namespace App\Interfaces\Telegram\Console;

use App\Modules\Identity\Application\IssueTelegramLinkCode;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class IssueLinkCommand extends Command
{
    protected $signature = 'telegram:link-account {user : SkyNet internal user ID}';

    protected $description = 'Generate a single-use Telegram account-link code (10 minute lifetime)';

    public function handle(IssueTelegramLinkCode $issue): int
    {
        $userId = (string) $this->argument('user');

        if (! ctype_digit($userId) || (int) $userId < 1) {
            $this->error('A valid internal user ID is required.');

            return self::FAILURE;
        }

        try {
            $code = $issue->handle((int) $userId);
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('In a PRIVATE chat with your SkyNet bot, send:');
        $this->line('/link '.$code);
        $this->warn('This is a one-time credential. It expires in 10 minutes; do not share or log it.');

        return self::SUCCESS;
    }
}
