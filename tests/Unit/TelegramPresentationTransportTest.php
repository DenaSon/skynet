<?php

it('routes all Telegram callback acknowledgement and message edits through the shared transport helper', function (): void {
    $root = app_path('Interfaces/Telegram');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $violations = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'TelegramUi.php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents !== false && preg_match('/->\\s*(?:answerCallbackQuery|editMessageText|editMessageCaption)\\s*\\(/', $contents) === 1) {
            $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($violations)->toBe([]);
});
