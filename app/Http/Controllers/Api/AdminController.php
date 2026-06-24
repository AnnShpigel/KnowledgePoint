<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contents;
use App\Models\Source;
use App\Models\User;
use App\Services\RdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер административной панели.
 *
 * Предоставляет CRUD-операции для управления тремя ключевыми сущностями системы:
 * источниками данных, контентом (с модерацией) и пользователями.
 *
 * Все методы доступны только администратору системы
 * (middleware auth:sanctum + admin).
 *
 * При изменении статуса контента на «approved» запись автоматически
 * синхронизируется с RDF-хранилищем Fuseki через RdfService.
 * При удалении контента — триплеты удаляются из графа.
 *
 * @package App\Http\Controllers\Api
 */
class AdminController extends Controller
{
    /**
     * Создать экземпляр контроллера.
     *
     * @param RdfService $rdf Сервис взаимодействия с RDF-хранилищем Fuseki.
     */
    public function __construct(private readonly RdfService $rdf) {}

    /**
     * Получить список всех источников данных.
     *
     * Возвращает все записи без пагинации, поскольку количество источников
     * в системе ограничено (3–10 для прототипа).
     *
     * @return JsonResponse JSON-массив объектов Source.
     */
    public function sources(): JsonResponse
    {
        return response()->json(Source::all());
    }

    /**
     * Создать новый источник данных.
     *
     * Источник описывает платформу или API, из которого агрегируется контент
     * (например, КиберЛенинка, Europeana, CrossRef).
     *
     * Поля:
     *  - name      (обязательное) — отображаемое название источника
     *  - url       (обязательное) — базовый URL платформы
     *  - type      (обязательное) — протокол доступа: api | oai-pmh | scraper
     *  - slug      (необязательное) — уникальный URL-идентификатор
     *  - is_active (необязательное) — активен ли источник для парсинга
     *
     * @param Request $request HTTP-запрос с данными нового источника.
     *
     * @return JsonResponse JSON с полями success, message, source. HTTP 201.
     */
    public function createSource(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'type' => 'required|in:api,oai-pmh,scraper',
            'slug' => 'nullable|string|max:100|unique:sources,slug',
            'is_active' => 'nullable|boolean',
        ]);

        $source = Source::create($request->only([
            'name', 'slug', 'description', 'url', 'api_endpoint',
            'api_key', 'type', 'is_active', 'parse_interval',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Источник создан.',
            'source'  => $source,
        ], 201);
    }

    /**
     * Обновить данные существующего источника.
     *
     * @param Request $request HTTP-запрос с обновлёнными данными.
     * @param int|string $id   Идентификатор источника.
     *
     * @return JsonResponse JSON с полями success, message, source.
     */
    public function updateSource(Request $request, int|string $id): JsonResponse
    {
        $source = Source::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'type' => 'required|in:api,oai-pmh,scraper',
            'is_active' => 'nullable|boolean',
        ]);

        $source->update($request->only([
            'name', 'description', 'url', 'api_endpoint',
            'api_key', 'type', 'is_active', 'parse_interval',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Источник обновлён.',
            'source'  => $source,
        ]);
    }

    /**
     * Удалить источник данных.
     *
     * @param int|string $id Идентификатор источника.
     *
     * @return JsonResponse JSON с полями success, message.
     */
    public function deleteSource(int|string $id): JsonResponse
    {
        Source::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Источник удалён.',
        ]);
    }

    /**
     * Получить список контента с пагинацией для административной панели.
     *
     * Поддерживает фильтрацию по статусу и источнику через query-параметры:
     *  - status    (необязательный): pending | approved | rejected
     *  - source_id (необязательный): ID источника
     *
     * @param Request $request HTTP-запрос с параметрами фильтрации.
     *
     * @return JsonResponse Пагинированный список контента с вложенным объектом source.
     */
    public function getContent(Request $request): JsonResponse
    {
        $query = Contents::with('source')->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('source_id')) {
            $query->where('source_id', $request->input('source_id'));
        }

        $perPage = min((int) $request->get('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Обновить запись контента (включая изменение статуса модерации).
     *
     * При изменении статуса вызывает RdfService::syncContent():
     *  - Статус «approved» → добавить/обновить триплеты в Fuseki.
     *  - Статус «rejected» или «pending» → удалить триплеты из Fuseki.
     *
     * @param Request    $request HTTP-запрос с обновлёнными данными.
     * @param int|string $id      Идентификатор записи контента.
     *
     * @return JsonResponse JSON с полями success, content.
     */
    public function updateContent(Request $request, int|string $id): JsonResponse
    {
        $content   = Contents::findOrFail($id);
        $oldStatus = $content->status;

        $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'type' => 'required|in:article,video,course,documentation,book',
            'url' => 'required|url|unique:contents,url,' . $id,
            'external_id' => 'nullable|string|max:255',
            'language' => 'required|in:ru,en',
            'published_at' => 'nullable|date',
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $content->update($request->only([
            'title', 'description', 'author', 'type',
            'url', 'external_id', 'language', 'published_at', 'status',
        ]));

        // Синхронизировать с Fuseki при любом изменении статуса
        // или при обновлении данных уже одобренного контента
        if ($content->status !== $oldStatus || $content->status === 'approved') {
            $this->rdf->syncContent($content->fresh());
        }

        return response()->json(['success' => true, 'content' => $content]);
    }

    /**
     * Удалить запись контента из базы данных.
     *
     * Перед физическим удалением вызывает RdfService::removeContent()
     * для удаления соответствующих триплетов из RDF-графа Fuseki.
     *
     * @param int|string $id Идентификатор записи контента.
     *
     * @return JsonResponse JSON с полем success.
     */
    public function deleteContent(int|string $id): JsonResponse
    {
        $content = Contents::findOrFail($id);

        $this->rdf->removeContent($content);
        $content->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Получить список пользователей с пагинацией.
     *
     * Возвращает только безопасные поля: id, email, role, is_active, created_at.
     * Поле password и другие чувствительные данные не включаются в ответ.
     *
     * @return JsonResponse Пагинированный список пользователей.
     */
    public function getUsers(): JsonResponse
    {
        $users = User::select('id', 'email', 'role', 'is_active', 'created_at')->paginate(20);

        return response()->json($users);
    }

    /**
     * Обновить данные пользователя: email, роль, статус активности.
     *
     * @param Request    $request HTTP-запрос с обновлёнными данными пользователя.
     * @param int|string $id      Идентификатор пользователя.
     *
     * @return JsonResponse JSON с полями success, user.
     */
    public function updateUser(Request $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'email' => 'required|email|unique:users,email,' . $id,
            'role' => 'required|in:user,admin',
            'is_active' => 'required|boolean',
        ]);

        $user->update($request->only(['email', 'role', 'is_active']));

        return response()->json(['success' => true, 'user' => $user]);
    }

    /**
     * Удалить пользователя из системы.
     *
     * Защита от самоудаления: администратор не может удалить собственный аккаунт.
     * Используется мягкое удаление (SoftDeletes) — запись помечается как удалённая,
     * но физически не стирается из базы данных.
     *
     * @param int|string $id Идентификатор пользователя.
     *
     * @return JsonResponse JSON с полем success или error (HTTP 403 при самоудалении).
     */
    public function deleteUser(int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->is(auth()->user())) {
            return response()->json([
                'error' => 'Нельзя удалить собственный аккаунт.',
            ], 403);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }
}
