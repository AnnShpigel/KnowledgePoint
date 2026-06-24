<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bookmark;
use App\Models\Contents;
use App\Models\SearchHistory;
use App\Models\User;
use App\Models\UserContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Сервис личного кабинета пользователя
 *
 * Инкапсулирует всю бизнес-логику для работы с историей поиска
 * и закладками (сохранёнными материалами).
 * Контроллер UserController делегирует все операции этому сервису.
 */
class UserService
{
    /**
     * Получить пагинированную историю поиска пользователя
     *
     * Записи возвращаются в порядке от новых к старым.
     *
     * @param User $user    — пользователь, чья история запрашивается
     * @param int  $perPage — количество записей на страницу (по умолчанию 20)
     * @return LengthAwarePaginator<SearchHistory>
     */
    public function getSearchHistory(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return SearchHistory::where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Удалить конкретную запись из истории поиска
     *
     * Проверяет принадлежность записи пользователю (owner-check через where user_id).
     *
     * @param User $user      — владелец записи
     * @param int  $historyId — идентификатор записи в таблице search_history
     * @return bool — true при успешном удалении
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException если запись не найдена
     *                                                               или принадлежит другому пользователю
     */
    public function deleteHistoryEntry(User $user, int $historyId): bool
    {
        $entry = SearchHistory::where('user_id', $user->id)
            ->findOrFail($historyId);

        return (bool) $entry->delete();
    }

    /**
     * Полностью очистить историю поиска пользователя
     *
     * @param User $user — пользователь, чья история очищается
     * @return int — количество удалённых записей
     */
    public function clearSearchHistory(User $user): int
    {
        return SearchHistory::where('user_id', $user->id)->delete();
    }

    /**
     * Получить пагинированный список закладок пользователя
     *
     * Возвращает записи user_contents с eager-загрузкой контента, источника и заметки
     * для исключения N+1 запросов.
     *
     * @param User $user    — пользователь, чьи закладки запрашиваются
     * @param int  $perPage — количество записей на страницу
     * @return LengthAwarePaginator<UserContent>
     */
    public function getBookmarks(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return UserContent::with(['content.source', 'bookmark'])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Добавить материал в закладки пользователя
     *
     * Операция идемпотентна: если материал уже сохранён — обновляет заметку.
     * Работает в транзакции для атомарности создания UserContent + Bookmark.
     *
     * @param User        $user      — пользователь, сохраняющий материал
     * @param int         $contentId — идентификатор контента (должен иметь статус approved)
     * @param string|null $note      — необязательная заметка к материалу
     * @return UserContent — созданная или существующая запись с загруженными связями
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException если контент не найден или не одобрен
     */
    public function addBookmark(User $user, int $contentId, ?string $note = null): UserContent
    {
        $content = Contents::approved()->findOrFail($contentId);

        return DB::transaction(function () use ($user, $content, $note): UserContent {
            $userContent = UserContent::firstOrCreate([
                'user_id' => $user->id,
                'content_id' => $content->id,
            ]);

            if ($note !== null) {
                Bookmark::updateOrCreate(
                    ['user_content_id' => $userContent->id],
                    ['note' => $note],
                );
            }

            return $userContent->load(['content.source', 'bookmark']);
        });
    }

    /**
     * Удалить материал из закладок пользователя
     *
     * Удаляет запись из user_contents. Заметка (bookmark) удаляется каскадно
     * за счёт onDelete cascade в миграции таблицы bookmark.
     *
     * @param User $user          — владелец закладки
     * @param int  $userContentId — идентификатор записи user_contents (не content_id!)
     * @return bool — true при успешном удалении
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException если закладка не найдена
     *                                                               или принадлежит другому пользователю
     */
    public function removeBookmark(User $user, int $userContentId): bool
    {
        $userContent = UserContent::where('user_id', $user->id)
            ->findOrFail($userContentId);

        return (bool) $userContent->delete();
    }

    /**
     * Проверить, добавлен ли материал в закладки пользователя
     *
     * @param User $user      — пользователь
     * @param int  $contentId — идентификатор контента
     * @return bool — true если материал уже сохранён
     */
    public function isBookmarked(User $user, int $contentId): bool
    {
        return UserContent::where('user_id', $user->id)
            ->where('content_id', $contentId)
            ->exists();
    }
}
