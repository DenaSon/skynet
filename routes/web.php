<?php

use App\Interfaces\Telegram\Http\RwBackupDownloadController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/live.php';

// One-use private Super Admin archive links. Authorization is repeated in controller.
Route::get('/internal/rw-backups/{token}', RwBackupDownloadController::class)
    ->name('rw-backup.download');

Route::get('/', function () {
    return view('welcome');
});
