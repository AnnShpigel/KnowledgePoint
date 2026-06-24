<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParseLog;
use App\Models\Source;
use App\Services\ParserFactory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер управления парсингом источников данных.
 *
 * Предоставляет административный API для:
 *  - Запуска парсера для конкретного источника
 *  - Просмотра истории парсинга с фильтрацией
 *  - Получения детальной информации о конкретном запуске
 *  - Управления настройками парсера (parse_settings) источника
 *
 * Все маршруты доступны только администраторам (middleware auth:sanctum + admin).
 *
 * Примечание о производительности: метод trigger() выполняет парсинг синхронно.
 * При большом числе записей (>500) рекомендуется использовать Artisan-команду
 * source:parse, а не HTTP-запрос, во избежание таймаута.
 *
 * @package App\Http\Controllers\Api
 */
class ParserController extends Controller
{
    /**
     * Максимальное число записей для синхронного HTTP-парсинга.
     * При превышении этого лимита возвращается предупреждение.
     */
    private const SYNC_PARSE_LIMIT = 200;

    /**
     * @param ParserFactory $factory Фабрика парсеров (внедряется через DI).
     */
    public function __construct(private readonly ParserFactory $factory) {}

    /**
     * Запустить парсинг указанного источника синхронно.
     *
     * Создаёт запись в parse_logs, выполняет парсинг, возвращает результат.
     * Максимальный лимит записей за один запрос: SYNC_PARSE_LIMIT = 200.
     *
     * Для полного импорта (>200 записей) используйте Artisan:
     *   php artisan source:parse {id} --limit=1000
     *
     * @param Request    $request HTTP-запрос.
     *                            Body: {"limit": 50, "skip_scrape": false}
     * @param int|string $id      ID источника.
     *
     * @return JsonResponse JSON с полями: success, result (статистика), log_id, duration_sec.
     */
    public function trigger(Request $request, int|string $id): JsonResponse
    {
        $source = Source::findOrFail($id);

        if (!$this->factory->supports($source)) {
            return response()->json([
                'success' => false,
                'message' => "Тип источника «{$source->type}» не поддерживается парсером. "
                    . 'Реализованные типы: oai-pmh.',
            ], 422);
        }

        // Проверить, не запущен ли уже парсинг этого источника
        $running = ParseLog::where('source_id', $source->id)
            ->where('status', 'running')
            ->exists();

        if ($running) {
            return response()->json([
                'success' => false,
                'message' => 'Парсинг этого источника уже выполняется. '
                    . 'Дождитесь завершения или проверьте зависший лог.',
            ], 409);
        }

        $request->validate([
            'limit'       => 'nullable|integer|min:1|max:' . self::SYNC_PARSE_LIMIT,
            'skip_scrape' => 'nullable|boolean',
        ]);

        $limit      = (int) ($request->input('limit', self::SYNC_PARSE_LIMIT));
        $skipScrape = (bool) $request->input('skip_scrape', false);

        // Применяем временные переопределения (не сохраняются в БД)
        $runtimeSettings = $source->parse_settings ?? [];
        $runtimeSettings['batch_limit'] = $limit;
        if ($skipScrape) {
            $runtimeSettings['scrape_pages'] = false;
        }
        $source->parse_settings = $runtimeSettings;

        // Создаём запись лога
        $log = ParseLog::create([
            'source_id' => $source->id,
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        $startTime = microtime(true);
        $parser = $this->factory->make($source);

        try {
            $result = $parser->parse($source);

            $duration = round(microtime(true) - $startTime, 2);

            $log->update([
                'status' => $result->hasErrors() ? 'failed' : 'success',
                'items_parsed' => $result->total(),
                'items_new' => $result->created,
                'items_updated' => $result->updated,
                'items_skipped' => $result->skipped,
                'pages_fetched' => $result->pagesFetched,
                'total_records' => $result->totalRecords,
                'error_message' => $result->hasErrors()
                    ? implode("\n", array_slice($result->errors, 0, 10))
                    : null,
                'finished_at' => Carbon::now(),
            ]);

            $source->updateQuietly(['last_parsed_at' => Carbon::now()]);

            return response()->json([
                'success' => true,
                'message' => $result->summary(),
                'log_id' => $log->id,
                'duration_sec' => $duration,
                'result' => [
                    'created' => $result->created,
                    'updated' => $result->updated,
                    'skipped' => $result->skipped,
                    'total' => $result->total(),
                    'pages_fetched' => $result->pagesFetched,
                    'total_records' => $result->totalRecords,
                    'errors' => $result->errors,
                ],
            ]);

        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ]);

            Log::error("[ParserController] Ошибка парсинга источника {$source->id}", [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'log_id' => $log->id,
                'duration_sec' => $duration,
            ], 500);
        }
    }

    /**
     * Получить историю парсинга всех источников с пагинацией.
     *
     * Фильтры (query-параметры):
     *  - source_id (int)    — только логи конкретного источника
     *  - status (string)    — running | success | failed
     *  - date_from (date)   — логи начиная с даты (Y-m-d)
     *  - date_to (date)     — логи по дату включительно (Y-m-d)
     *
     * @param Request $request HTTP-запрос с параметрами фильтрации.
     *
     * @return JsonResponse Пагинированный список ParseLog с вложенным source.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = ParseLog::with('source:id,name,slug,type')
            ->orderBy('started_at', 'desc');

        if ($request->filled('source_id')) {
            $query->where('source_id', $request->integer('source_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->input('date_to'));
        }

        return response()->json($query->paginate(25));
    }

    /**
     * Получить историю парсинга конкретного источника.
     *
     * Возвращает последние 20 записей в порядке убывания даты запуска.
     *
     * @param int|string $id ID источника.
     *
     * @return JsonResponse JSON с полями: source (краткие данные), logs (список).
     */
    public function sourceLog(int|string $id): JsonResponse
    {
        $source = Source::select('id', 'name', 'slug', 'type', 'is_active', 'last_parsed_at')
            ->findOrFail($id);

        $logs = ParseLog::where('source_id', $source->id)
            ->orderBy('started_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'source' => $source,
            'logs'   => $logs,
        ]);
    }

    /**
     * Получить детальную информацию об одном запуске парсинга.
     *
     * Включает полный список ошибок (error_message), статистику по записям
     * и данные связанного источника.
     *
     * @param int|string $id ID записи ParseLog.
     *
     * @return JsonResponse JSON с полным объектом ParseLog + source.
     */
    public function logDetail(int|string $id): JsonResponse
    {
        $log = ParseLog::with('source:id,name,slug,type')->findOrFail($id);

        // Вычисляем продолжительность, если парсинг завершён
        $durationSec = null;
        if ($log->started_at && $log->finished_at) {
            $durationSec = $log->finished_at->diffInSeconds($log->started_at);
        }

        return response()->json([
            'log'          => $log,
            'duration_sec' => $durationSec,
            'speed'        => $durationSec > 0
                ? round($log->items_parsed / $durationSec, 1) . ' зап/сек'
                : null,
        ]);
    }

    /**
     * Обновить настройки парсера (parse_settings) источника.
     *
     * Принимает JSON-объект с произвольными ключами настроек.
     * Существующие ключи, не переданные в запросе, сохраняются (merge-семантика).
     *
     * Пример запроса:
     * {
     *   "oai_endpoint":    "https://cyberleninka.ru/oai",
     *   "batch_limit":     100,
     *   "scrape_pages":    true,
     *   "scrape_delay_ms": 800
     * }
     *
     * @param Request    $request HTTP-запрос с новыми настройками.
     * @param int|string $id      ID источника.
     *
     * @return JsonResponse JSON с полями: success, parse_settings.
     */
    public function updateSettings(Request $request, int|string $id): JsonResponse
    {
        $source = Source::findOrFail($id);

        $request->validate([
            'oai_endpoint'     => 'nullable|url',
            'metadata_prefix'  => 'nullable|string|max:50',
            'oai_set'          => 'nullable|string|max:255',
            'batch_limit'      => 'nullable|integer|min:0',
            'scrape_pages'     => 'nullable|boolean',
            'scrape_delay_ms'  => 'nullable|integer|min:0|max:10000',
            'request_delay_ms' => 'nullable|integer|min:0|max:10000',
        ]);

        // Мержим с существующими настройками (не перезаписываем полностью)
        $current  = $source->parse_settings ?? [];
        $incoming = array_filter(
            $request->only([
                'oai_endpoint', 'metadata_prefix', 'oai_set',
                'batch_limit', 'scrape_pages', 'scrape_delay_ms', 'request_delay_ms',
            ]),
            fn ($v) => $v !== null,
        );

        $merged = array_merge($current, $incoming);
        $source->update(['parse_settings' => $merged]);

        return response()->json([
            'success'        => true,
            'parse_settings' => $merged,
        ]);
    }
}
