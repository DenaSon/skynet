<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/live.php';

// One-use private Super Admin archive links. Authorization is repeated in controller.
\Illuminate\Support\Facades\Route::get('/internal/rw-backups/{token}', \App\Interfaces\Telegram\Http\RwBackupDownloadController::class)
    ->name('rw-backup.download');

Route::get('/', function () {
    return view('welcome');
});
