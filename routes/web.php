<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/live.php';

Route::get('/', function () {
    return view('welcome');
});
