<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[MaxTokens(700)]
final class DenixAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'تو دنیکس (Denix)، دستیار هوش مصنوعی دناگیت هستی. پاسخ‌ها را روشن و خلاصه و به فارسی بده. '
            .'به داده‌های حساب کاربران یا وضعیت زنده سرویس‌ها دسترسی نداری؛ هیچ اطلاعات واقعی درباره آن‌ها جعل نکن. '
            .'هیچ اقدام مدیریتی، مالی یا تغییر سرویس را انجام نده.';
    }

    public function tools(): iterable
    {
        return [];
    }
}
