<?php

use Illuminate\Support\Facades\Route;

// SPA catch-all — отдаём React для всех не-API маршрутов.
// Регулярное выражение явно исключает пути /api/... чтобы они
// всегда обрабатывались routes/api.php, а не этим маршрутом.
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api).*$');
