<?php

use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// The existing BotFather token remains the legacy compatibility runtime.
// Admin registrations are isolated in a dedicated file; old Sales and shared
// registrations remain reachable here until a separately verified cutover.
require base_path('app/Interfaces/Telegram/routes/shared.php');
require base_path('app/Interfaces/Telegram/routes/admin.php');
require base_path('app/Interfaces/Telegram/routes/legacy_sales.php');
require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
