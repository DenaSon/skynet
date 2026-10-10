<?php

namespace App\Interfaces\Telegram\Console;

use App\Modules\Identity\Application\IssueTelegramLinkCode;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class IssueLinkCommand extends Command
{
    protected $signature = 'telegram:link-account {user : SkyNet internal user ID}';

    protected $description = 'Generate a single-use Telegram account-link code (10 minute lifetime)';

    public function handle(IssueTelegramLinkCode $issue): int
    {
        $user = User::query()->find($this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        try {
            $code = $issue->handle($user);
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
