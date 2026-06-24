<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreBookmarkRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер личного кабинета пользователя
 *
 * Управляет историей поиска и закладками (сохранёнными материалами).
 * Все маршруты защищены middleware auth:sanctum.
 * Бизнес-логика полностью делегирована в UserService.
 *
 * Маршруты:
 *   GET    /api/user/history        — список истории поиска
 *   DELETE /api/user/history/{id}   — удалить запись истории
 *   DELETE /api/user/history        — очистить всю историю
 *   GET    /api/user/bookmarks      — список закладок
 *   POST   /api/user/bookmarks      — добавить закладку
 *   DELETE /api/user/bookmarks/{id} — удалить закладку
 */
class UserController extends Controller
{
    /**
     * Инициализация с инъекцией зависимостей
     *
     * @param UserService $userService — сервис для работы с данными личного кабинета
     */
    public function __construct(
        private readonly UserService $userService,
    ) {}

    /**
     * Получить историю поиска текущего пользователя
     *
     * Возвращает пагинированный список поисковых запросов,
     * отсортированных от новых к старым.
     *
     * @param Request $request — HTTP-запрос; поддерживает параметр per_page (default: 20)
     * @return JsonResponse — пагинированный список search_history
     *
     * GET /api/user/history?per_page=20
     */
    public function history(Request $request): JsonResponse
    {
        $history = $this->userService->getSearchHistory(
            user: $request->user(),
            perPage: (int) $request->input('per_page', 20),
        );

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Удалить конкретную запись из истории поиска
     *
     * @param Request $request — HTTP-запрос
     * @param int     $id      — идентификатор записи search_history
     * @return JsonResponse — подтверждение удаления (404 если не найдено)
     *
     * DELETE /api/user/history/{id}
     */
    public function deleteHistoryEntry(Request $request, int $id): JsonResponse
    {
        $this->userService->deleteHistoryEntry($request->user(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Запись удалена из истории',
        ]);
    }

    /**
     * Очистить всю историю поиска пользователя
     *
     * @param Request $request — HTTP-запрос
     * @return JsonResponse — количество удалённых записей
     *
     * DELETE /api/user/history
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $count = $this->userService->clearSearchHistory($request->user());

        return response()->json([
            'success' => true,
            'message' => "Удалено записей: {$count}",
            'data' => ['deleted_count' => $count],
        ]);
    }

    /**
     * Получить список закладок (сохранённых материалов) пользователя
     *
     * Возвращает пагинированный список user_contents с вложенными данными:
     * контент (title, author, url, type, ...), источник и заметка.
     *
     * @param Request $request — HTTP-запрос; поддерживает параметр per_page (default: 20)
     * @return JsonResponse — пагинированный список закладок
     *
     * GET /api/user/bookmarks?per_page=20
     */
    public function bookmarks(Request $request): JsonResponse
    {
        $bookmarks = $this->userService->getBookmarks(
            user: $request->user(),
            perPage: (int) $request->input('per_page', 20),
        );

        return response()->json([
            'success' => true,
            'data' => $bookmarks,
        ]);
    }

    /**
     * Добавить материал в закладки пользователя
     *
     * Идемпотентная операция: повторный вызов для уже сохранённого материала
     * обновит заметку, но не создаст дублирующую запись.
     * Сохранить можно только одобренный (approved) контент.
     *
     * @param StoreBookmarkRequest $request — валидированный запрос
     *                                        (content_id: int, note?: string)
     * @return JsonResponse — созданная запись с кодом 201
     *
     * POST /api/user/bookmarks
     * Body: { "content_id": 42, "note": "Важная статья" }
     */
    public function addBookmark(StoreBookmarkRequest $request): JsonResponse
    {
        $userContent = $this->userService->addBookmark(
            user: $request->user(),
            contentId: $request->integer('content_id'),
            note: $request->input('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Материал добавлен в закладки',
            'data' => $userContent,
        ], 201);
    }

    /**
     * Удалить материал из закладок пользователя
     *
     * Принимает идентификатор записи user_contents (не content_id!).
     * Каскадно удаляет заметку (bookmark) если она существует.
     *
     * @param Request $request — HTTP-запрос
     * @param int     $id      — идентификатор записи user_contents
     * @return JsonResponse — подтверждение удаления (404 если не найдено)
     *
     * DELETE /api/user/bookmarks/{id}
     */
    public function removeBookmark(Request $request, int $id): JsonResponse
    {
        $this->userService->removeBookmark($request->user(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Материал удалён из закладок',
        ]);
    }
}
