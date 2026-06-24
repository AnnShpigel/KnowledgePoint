<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTO\SearchFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Contents;
use App\Models\Source;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер поиска материалов и публичного каталога источников
 *
 * Предоставляет два публичных эндпоинта:
 * — GET /api/search   — полнотекстовый поиск с фильтрацией
 * — GET /api/sources  — список активных источников для фильтра поиска
 *
 * Поиск доступен анонимным пользователям и авторизованным.
 * Для авторизованных запрос сохраняется в историю поиска (через SearchService).
 *
 * Контроллер — тонкий: вся бизнес-логика делегирована в SearchService.
 */
class SearchController extends Controller
{
    /**
     * Инициализация с инъекцией зависимостей
     *
     * @param SearchService $searchService — сервис поиска по материалам
     */
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    /**
     * Выполнить полнотекстовый поиск по материалам базы знаний
     *
     * Маршрут публичный — не требует аутентификации.
     * При наличии Bearer-токена пользователь определяется через guard 'sanctum'
     * и запрос записывается в историю поиска.
     *
     * Формат ответа:
     * {
     *   "success": true,
     *   "data": {
     *     "items": [...],           // массив материалов с eager-loaded source
     *     "pagination": {
     *       "current_page": 1,
     *       "last_page": 5,
     *       "total": 72,
     *       "per_page": 15
     *     }
     *   },
     *   "meta": { "query": "..." }
     * }
     *
     * @param SearchRequest $request — валидированный HTTP-запрос с параметрами поиска
     * @return JsonResponse — пагинированные результаты поиска
     *
     * GET /api/search?q=...&type=...&year_from=...&year_to=...&lang=...&source_id=...&per_page=...&page=...
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $filters = new SearchFilters(
            query:    $request->string('q')->toString(),
            type:     $request->input('type') ?: null,
            yearFrom: $request->integer('year_from') ?: null,
            yearTo:   $request->integer('year_to')   ?: null,
            lang:     $request->input('lang')         ?: null,
            sourceId: $request->integer('source_id')  ?: null,
            sort:     $request->input('sort', 'relevance'),
            perPage:  $request->integer('per_page', 15),
        );

        $result    = $this->searchService->search($filters, $request->user('sanctum'));
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'data'    => [
                'items'          => $paginator->items(),
                'semantic_items' => $result['semantic_items'],
                'pagination'     => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                ],
            ],
            'meta' => [
                'query'          => $filters->query,
                'semantic_count' => count($result['semantic_items']),
            ],
        ]);
    }

    /**
     * Получить одобренный материал по ID
     *
     * @param int $id — идентификатор материала
     * @return JsonResponse
     *
     * GET /api/content/{id}
     */
    public function show(int $id): JsonResponse
    {
        $content = Contents::approved()->with('source')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $content,
        ]);
    }

    /**
     * Получить список активных источников для фильтра поиска
     *
     * Возвращает только id, name и slug — минимальный набор для выпадающего списка.
     * Маршрут публичный, кеширование не предусмотрено (прототип).
     *
     * @return JsonResponse — список активных источников
     *
     * GET /api/sources
     */
    public function sources(): JsonResponse
    {
        $sources = Source::where('is_active', true)
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sources,
        ]);
    }
}
