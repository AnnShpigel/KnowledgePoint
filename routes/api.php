<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ParserController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SparqlController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Здесь регистрируются все API-маршруты приложения
| Все роуты автоматически получают префикс /api (настроено в RouteServiceProvider)
| Например: POST /api/auth/register
|
*/

// ──────────────────────────────────────────────────────────────────────────
// АУТЕНТИФИКАЦИЯ
// ──────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {

    // Публичные эндпоинты — rate limiting 10 запросов в минуту для защиты от брутфорса
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);

        Route::post('/profile/email', [ProfileController::class, 'updateEmail']);
        Route::post('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/delete', [ProfileController::class, 'deleteProfile']);
    });
});

// ──────────────────────────────────────────────────────────────────────────
// ЛИЧНЫЙ КАБИНЕТ ПОЛЬЗОВАТЕЛЯ
// ──────────────────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->prefix('user')->group(function () {

    // История поиска
    // GET    /api/user/history        — список (пагинация, ?per_page=N)
    // DELETE /api/user/history        — очистить всю историю
    // DELETE /api/user/history/{id}   — удалить одну запись
    Route::get('/history', [UserController::class, 'history']);
    Route::delete('/history', [UserController::class, 'clearHistory']);
    Route::delete('/history/{id}', [UserController::class, 'deleteHistoryEntry']);

    // Закладки (сохранённые материалы)
    // GET    /api/user/bookmarks      — список (пагинация, ?per_page=N)
    // POST   /api/user/bookmarks      — добавить { content_id, note? }
    // DELETE /api/user/bookmarks/{id} — удалить по id записи user_contents
    Route::get('/bookmarks', [UserController::class, 'bookmarks']);
    Route::post('/bookmarks', [UserController::class, 'addBookmark']);
    Route::delete('/bookmarks/{id}', [UserController::class, 'removeBookmark']);
});

// ──────────────────────────────────────────────────────────────────────────
// ПОИСК И КАТАЛОГ ИСТОЧНИКОВ — публичные эндпоинты
// ──────────────────────────────────────────────────────────────────────────

// GET /api/search?q=...&type=...&year_from=...&year_to=...&lang=...&source_id=...
// Полнотекстовый поиск по одобренным материалам.
// Для авторизованных пользователей запрос записывается в историю поиска.
Route::get('/search', [SearchController::class, 'search']);

// GET /api/sources — список активных источников для фильтра поиска
Route::get('/sources', [SearchController::class, 'sources']);

// GET /api/content/{id} — получить одобренный материал по ID (публичный)
Route::get('/content/{id}', [SearchController::class, 'show']);

// ──────────────────────────────────────────────────────────────────────────
// SPARQL — публичные эндпоинты (только SELECT)
// ──────────────────────────────────────────────────────────────────────────

// GET /api/sparql?query=SELECT... — выполнить SPARQL SELECT
Route::get('/sparql', [SparqlController::class, 'query']);

// GET /api/sparql/status — статус доступности Fuseki
Route::get('/sparql/status', [SparqlController::class, 'status']);

// GET /api/content/{id}/graph — граф семантических связей материала
Route::get('/content/{id}/graph', [SparqlController::class, 'relatedGraph']);

// ──────────────────────────────────────────────────────────────────────────
// АДМИНИСТРИРОВАНИЕ
// ──────────────────────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    // Источники
    Route::get('/sources', [AdminController::class, 'sources']);
    Route::post('/sources', [AdminController::class, 'createSource']);
    Route::put('/sources/{id}', [AdminController::class, 'updateSource']);
    Route::delete('/sources/{id}', [AdminController::class, 'deleteSource']);

    // Контент (модерация)
    Route::get('/content', [AdminController::class, 'getContent']);
    Route::put('/content/{id}', [AdminController::class, 'updateContent']);
    Route::delete('/content/{id}', [AdminController::class, 'deleteContent']);

    // Пользователи
    Route::get('/users', [AdminController::class, 'getUsers']);
    Route::put('/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);

    // SPARQL UPDATE (только admin)
    Route::post('/sparql/update', [SparqlController::class, 'update']);

    // RDF: управление графом, загрузка дампов, экспорт
    Route::post('/rdf/upload', [SparqlController::class, 'uploadDump']);
    Route::post('/rdf/ontology', [SparqlController::class, 'uploadOntology']);
    Route::post('/rdf/dataset', [SparqlController::class, 'ensureDataset']);
    Route::get('/rdf/export', [SparqlController::class, 'export']);

    // ── Парсинг источников ─────────────────────────────────────────────────
    // POST  /admin/sources/{id}/parse           — запустить парсинг
    // GET   /admin/sources/{id}/parse-logs      — история конкретного источника
    // PATCH /admin/sources/{id}/parse-settings  — обновить настройки парсера
    // GET   /admin/parse-logs                   — все логи с фильтрацией
    // GET   /admin/parse-logs/{id}              — детали одного запуска

    Route::post('/sources/{id}/parse', [ParserController::class, 'trigger']);
    Route::get('/sources/{id}/parse-logs', [ParserController::class, 'sourceLog']);
    Route::patch('/sources/{id}/parse-settings', [ParserController::class, 'updateSettings']);
    Route::get('/parse-logs', [ParserController::class, 'logs']);
    Route::get('/parse-logs/{id}', [ParserController::class, 'logDetail']);
});

// ──────────────────────────────────────────────────────────────────────────
// УТИЛИТЫ
// ──────────────────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
