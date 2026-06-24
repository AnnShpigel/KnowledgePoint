<?php

declare(strict_types=1);

namespace App\Services\Parsers;

use App\Models\Contents;
use App\Models\Source;
use App\Services\Parsers\Contracts\ParserInterface;
use App\Services\Parsers\DTO\ParseResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Парсер открытого API Министерства культуры РФ (opendata.mkrf.ru).
 *
 * Загружает метаданные музейных экспонатов из Государственного каталога
 * Музейного фонда РФ (v2/museum-exhibits). Тестировался на коллекции
 * документов Государственного Эрмитажа.
 *
 * Алгоритм работы:
 *  1. Выполнить GET-запрос к endpoint с фильтром из parse_settings
 *  2. Разобрать JSON-ответ, извлечь массив data[]
 *  3. Для каждого экспоната сформировать URL-ссылку на источник и сохранить в contents
 *  4. Если в ответе есть поле nextPage — перейти к следующей странице
 *  5. Остановиться при достижении лимита или конца списка
 *
 * Настройки источника (Source::parse_settings):
 *  - filter_url   string  Полный URL первого запроса с параметрами фильтра (обязательно)
 *  - batch_size   int     Количество записей на страницу запроса (default: 100, max: 1000)
 *  - batch_limit  int     Максимум записей за один запуск (0 = без ограничений)
 *  - request_delay_ms int Задержка между запросами в мс (default: 500)
 *
 * @package App\Services\Parsers
 */
final class MkrfApiParser implements ParserInterface
{
    /**
     * Базовый URL для формирования ссылки на источник (страница экспоната).
     */
    private const SOURCE_URL_BASE = 'https://opendata.mkrf.ru/opendata/7705851331-museum-exhibits/4/';

    /**
     * Тип контента для всех экспонатов.
     * В фильтре запрашиваем типологию "документы".
     */
    private const CONTENT_TYPE = 'documentation';

    /**
     * Язык по умолчанию (Эрмитаж — русскоязычный ресурс).
     */
    private const DEFAULT_LANGUAGE = 'ru';

    /**
     * Количество попыток при SSL/сетевых ошибках.
     */
    private const MAX_RETRIES = 3;

    private readonly Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 60,
            'connect_timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'verify' => false,
        ]);
    }

    /**
     * Запустить парсинг МКРФ API источника.
     *
     * @param Source        $source     Источник данных с заполненным parse_settings.
     * @param callable|null $onProgress Коллбэк прогресса: function(int $total, string $title): void
     *
     * @return ParseResult Статистика: created, updated, skipped, errors.
     *
     * @throws \RuntimeException Если endpoint не задан или недоступен.
     */
    public function parse(Source $source, ?callable $onProgress = null): ParseResult
    {
        $settings = $this->resolveSettings($source);
        $result   = new ParseResult();

        // Стартовый URL берём из настроек источника (содержит фильтр и размер страницы)
        $nextUrl     = $settings['filter_url'];
        $apiKey      = $settings['api_key'];
        $totalParsed = 0;

        Log::info("[MkrfApiParser] Старт парсинга источника «{$source->name}»", [
            'source_id' => $source->id,
            'filter_url' => $nextUrl,
            'limit' => $settings['batch_limit'],
        ]);

        do {
            $response = $this->fetchPage($nextUrl, $apiKey);

            if ($response === null) {
                $result->addError("Не удалось загрузить страницу API: {$nextUrl}");
                break;
            }

            $result->pagesFetched++;

            // Общее количество записей (сервер сообщает в первом ответе)
            if (isset($response['total']) && $result->totalRecords === null) {
                $result->totalRecords = (int) $response['total'];
            }

            $items = $response['data'] ?? [];

            foreach ($items as $item) {
                if ($settings['batch_limit'] > 0 && $totalParsed >= $settings['batch_limit']) {
                    Log::info("[MkrfApiParser] Достигнут лимит батча: {$settings['batch_limit']}");
                    break 2;
                }

                $record = $this->normalizeRecord($item);

                if ($record === null) {
                    $result->addError("Пропущена запись без ID или названия: " . json_encode($item['_id'] ?? 'unknown'));
                    continue;
                }

                $this->saveRecord($source, $record, $result);
                $totalParsed++;

                if ($onProgress !== null) {
                    ($onProgress)($totalParsed, $record['title']);
                }
            }

            // Следующая страница — API возвращает полный URL
            $nextUrl = $response['nextPage'] ?? null;

            if ($nextUrl !== null && $settings['request_delay_ms'] > 0) {
                usleep($settings['request_delay_ms'] * 1_000);
            }

        } while ($nextUrl !== null);

        Log::info("[MkrfApiParser] Парсинг завершён: {$result->summary()}", [
            'source_id' => $source->id,
        ]);

        return $result;
    }

    /**
     * Загрузить одну страницу API и вернуть декодированный JSON.
     *
     * @param string $url Полный URL запроса.
     *
     * @return array<string, mixed>|null Декодированный ответ или null при ошибке.
     */
    private function fetchPage(string $url, ?string $apiKey): ?array
    {
        $body = $this->fetchWithRetry($url, $apiKey);

        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['data'])) {
            Log::error('[MkrfApiParser] Неожиданный формат ответа API', [
                'url' => $url,
                'response_length' => strlen($body),
            ]);
            return null;
        }

        return $decoded;
    }

    /**
     * Выполнить GET-запрос с повторными попытками при SSL/сетевых ошибках.
     *
     * Задержка между попытками увеличивается экспоненциально: 2s, 4s, 8s.
     *
     * @param string $url URL запроса.
     *
     * @return string|null Тело ответа или null при окончательной ошибке.
     */
    private function fetchWithRetry(string $url, ?string $apiKey): ?string
    {
        $options = [];
        if ($apiKey !== null) {
            $options['headers'] = ['X-API-KEY' => $apiKey];
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->http->get($url, $options);
                return (string) $response->getBody();
            } catch (GuzzleException $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    Log::error("[MkrfApiParser] HTTP ошибка при загрузке страницы API", [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                $delay = 2 ** $attempt; // 2s, 4s, 8s
                Log::warning("[MkrfApiParser] Попытка {$attempt}/" . self::MAX_RETRIES . " не удалась, повтор через {$delay}s", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
            }
        }

        return null;
    }

    /**
     * Преобразовать элемент из массива data[] в нормализованную запись.
     *
     * Структура элемента:
     *  - _id         string  Внутренний MongoDB ID
     *  - nativeId    string  Нативный ID экспоната
     *  - data        object  Данные экспоната (name, authors, museum, typology, ...)
     *
     * @param array<string, mixed> $item Элемент из data[].
     *
     * @return array<string, mixed>|null Нормализованная запись или null, если данные неполные.
     */
    private function normalizeRecord(array $item): ?array
    {
        $data = $item['data'] ?? null;

        if (!is_array($data) || empty($data['id']) || empty($data['name'])) {
            return null;
        }

        $id    = (int) $data['id'];
        $title = trim((string) $data['name']);

        if ($title === '') {
            return null;
        }

        // Авторы (для документов обычно пустой массив)
        $authors = [];
        foreach ($data['authors'] ?? [] as $author) {
            $name = trim((string) $author);
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        // Описание: берём из поля description, иначе собираем из доступных полей
        $description = $this->buildDescription($data);

        // Теги: материалы/техники + типология + место создания + ключевые слова
        $tags = $this->buildTags($data);

        // Дата создания предмета
        $publishedAt = $this->normalizeDate($data['startDate'] ?? $data['regDate'] ?? null);

        // URL-ссылка на страницу экспоната
        $url = self::SOURCE_URL_BASE . $id;

        return [
            'id'          => $id,
            'title'       => $title,
            'description' => $description,
            'author'      => $authors ? implode(', ', $authors) : null,
            'url'         => $url,
            'external_id' => (string) $id,
            'published_at' => $publishedAt,
            'tags'        => $tags,
        ];
    }

    /**
     * Сформировать описание экспоната из доступных полей.
     *
     * Для документов поле description часто отсутствует.
     * В этом случае строим информативное описание из других полей.
     *
     * @param array<string, mixed> $data Поле data из элемента API.
     *
     * @return string|null Описание или null, если нет данных.
     */
    private function buildDescription(array $data): ?string
    {
        if (!empty($data['description'])) {
            return trim((string) $data['description']);
        }

        return null;
    }

    /**
     * Собрать список тегов из полей экспоната.
     *
     * @param array<string, mixed> $data Поле data из элемента API.
     *
     * @return list<string> Уникальные теги в нижнем регистре.
     */
    private function buildTags(array $data): array
    {
        $tags = [];

        // Материалы и техники
        foreach ($data['technologies'] ?? [] as $tech) {
            $t = mb_strtolower(trim((string) $tech));
            if ($t !== '') {
                $tags[] = $t;
            }
        }

        // Типология
        if (!empty($data['typology']['name'])) {
            $tags[] = mb_strtolower(trim($data['typology']['name']));
        }

        // Место создания
        if (!empty($data['productionPlace'])) {
            $tags[] = mb_strtolower(trim($data['productionPlace']));
        }

        // Ключевые слова (mainWords — строка, может содержать несколько через запятую)
        if (!empty($data['mainWords'])) {
            foreach (explode(',', (string) $data['mainWords']) as $kw) {
                $k = mb_strtolower(trim($kw));
                if ($k !== '') {
                    $tags[] = $k;
                }
            }
        }

        // Эпоха
        if (!empty($data['epoch']['name'])) {
            $tags[] = mb_strtolower(trim($data['epoch']['name']));
        }

        return array_values(array_unique($tags));
    }

    /**
     * Сохранить или обновить запись экспоната в таблице contents.
     *
     * Дедупликация по полю url (как в OaiPmhParser).
     * Все записи сохраняются со статусом 'pending'.
     *
     * @param Source               $source Источник данных.
     * @param array<string, mixed> $record Нормализованная запись.
     * @param ParseResult          $result Объект для накопления статистики.
     */
    private function saveRecord(Source $source, array $record, ParseResult $result): void
    {
        try {
            $existing = Contents::where('url', $record['url'])->first();

            if ($existing === null) {
                Contents::create([
                    'source_id'   => $source->id,
                    'title'       => $record['title'],
                    'description' => $record['description'],
                    'author'      => $record['author'],
                    'type'        => self::CONTENT_TYPE,
                    'url'         => $record['url'],
                    'external_id' => $record['external_id'],
                    'language'    => self::DEFAULT_LANGUAGE,
                    'published_at' => $record['published_at'],
                    'status'      => 'pending',
                    'tags'        => $record['tags'],
                ]);
                $result->created++;
            } else {
                $updates = [];

                if (empty($existing->description) && !empty($record['description'])) {
                    $updates['description'] = $record['description'];
                }

                if (empty($existing->author) && !empty($record['author'])) {
                    $updates['author'] = $record['author'];
                }

                if (!empty($record['tags'])) {
                    $existingTags = $existing->tags ?? [];
                    $merged       = array_values(array_unique(array_merge($existingTags, $record['tags'])));
                    if (count($merged) > count($existingTags)) {
                        $updates['tags'] = $merged;
                    }
                }

                if (empty($existing->published_at) && !empty($record['published_at'])) {
                    $updates['published_at'] = $record['published_at'];
                }

                if ($updates !== []) {
                    $existing->update($updates);
                    $result->updated++;
                } else {
                    $result->skipped++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[MkrfApiParser] Ошибка сохранения записи: {$record['url']}", [
                'error' => $e->getMessage(),
            ]);
            $result->addError("Ошибка сохранения [{$record['url']}]: {$e->getMessage()}");
        }
    }

    /**
     * Получить и нормализовать настройки парсера из Source::parse_settings.
     *
     * @param Source $source Источник данных.
     *
     * @return array<string, mixed> Нормализованные настройки с дефолтами.
     *
     * @throws \RuntimeException Если не задан обязательный параметр filter_url.
     */
    private function resolveSettings(Source $source): array
    {
        $settings = $source->parse_settings ?? [];

        $filterUrl = $settings['filter_url'] ?? $source->api_endpoint ?? null;

        if (!$filterUrl) {
            throw new \RuntimeException(
                "Для источника «{$source->name}» не задан filter_url в parse_settings."
            );
        }

        // Добавляем параметр размера страницы к стартовому URL
        $batchSize = (int) ($settings['batch_size'] ?? 100);
        $filterUrl = $this->appendBatchSize($filterUrl, $batchSize);

        return [
            'filter_url'       => $filterUrl,
            'batch_size'       => $batchSize,
            'batch_limit'      => (int) ($settings['batch_limit'] ?? 0),
            'request_delay_ms' => (int) ($settings['request_delay_ms'] ?? 500),
            'api_key'          => $source->api_key ?? null,
        ];
    }

    /**
     * Добавить параметр l= (размер страницы) к URL, если его ещё нет.
     *
     * @param string $url       Исходный URL.
     * @param int    $batchSize Размер страницы.
     *
     * @return string URL с параметром l=.
     */
    private function appendBatchSize(string $url, int $batchSize): string
    {
        if (str_contains($url, '&l=') || str_contains($url, '?l=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'l=' . $batchSize;
    }

    /**
     * Нормализовать строку даты к формату Y-m-d.
     *
     * API МКРФ возвращает даты в ISO 8601 (2022-03-03T15:54:55.432Z).
     *
     * @param mixed $raw Сырое значение даты.
     *
     * @return string|null Дата Y-m-d или null при пустом/нераспознанном значении.
     */
    private function normalizeDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
