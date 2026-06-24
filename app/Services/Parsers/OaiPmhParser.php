<?php

declare(strict_types=1);

namespace App\Services\Parsers;

use App\Models\Contents;
use App\Models\Source;
use App\Services\Parsers\Contracts\ParserInterface;
use App\Services\Parsers\DTO\ParseResult;
use App\Services\Parsers\DTO\ScrapedArticleData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Парсер источников данных, поддерживающих протокол OAI-PMH 2.0.
 *
 * OAI-PMH (Open Archives Initiative Protocol for Metadata Harvesting) —
 * стандартный протокол обмена метаданными между репозиториями. Использует
 * XML-ответы с поддержкой курсорной пагинации через resumptionToken.
 *
 * Проверен на КиберЛенинке (https://cyberleninka.ru/oai).
 * Должен работать с любым совместимым OAI-PMH 2.0 источником.
 *
 * Алгоритм работы:
 *  1. Запросить первую страницу: verb=ListRecords&metadataPrefix=oai_dc
 *  2. Разобрать XML, извлечь записи (dc:title, dc:creator, dc:identifier, ...)
 *  3. Для каждой записи (опционально) запустить скрапинг страницы статьи
 *  4. Сохранить/обновить запись в таблице contents
 *  5. Если в ответе есть resumptionToken — продолжить с следующей страницей
 *  6. Остановиться при достижении лимита или конца списка
 *
 * Настройки источника (Source::parse_settings):
 *  - oai_endpoint      string   OAI-PMH endpoint (обязательно)
 *  - metadata_prefix   string   Формат метаданных (default: oai_dc)
 *  - oai_set           string?  Ограничить набором записей (default: null)
 *  - batch_limit       int      Максимум записей за один запуск (0 = без лимита)
 *  - scrape_pages      bool     Скрапировать страницы для обогащения (default: true)
 *  - scrape_delay_ms   int      Задержка между скрапинг-запросами (default: 800)
 *  - request_delay_ms  int      Задержка между OAI-PMH страницами (default: 500)
 *
 * @package App\Services\Parsers
 */
final class OaiPmhParser implements ParserInterface
{
    /**
     * Пространство имён Dublin Core в OAI-PMH XML-ответах.
     */
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    /**
     * Пространство имён OAI-DC в XML-ответах.
     */
    private const NS_OAI_DC = 'http://www.openarchives.org/OAI/2.0/oai_dc/';

    /**
     * Пространство имён OAI-PMH 2.0.
     */
    private const NS_OAI = 'http://www.openarchives.org/OAI/2.0/';

    /**
     * Тип контента для всех статей из OAI-PMH (статьи КиберЛенинки всегда article).
     */
    private const DEFAULT_CONTENT_TYPE = 'article';

    /**
     * Язык по умолчанию для записей, у которых не удалось определить язык.
     * КиберЛенинка преимущественно русскоязычный ресурс.
     */
    private const DEFAULT_LANGUAGE = 'ru';

    /**
     * Количество попыток при SSL/сетевых ошибках.
     */
    private const MAX_RETRIES = 3;

    private readonly Client $http;

    /**
     * @param CyberLenikaPageScraper $scraper Сервис скрапинга HTML-страниц статей.
     */
    public function __construct(private readonly CyberLenikaPageScraper $scraper)
    {
        $this->http = new Client([
            'timeout' => 60,
            'connect_timeout' => 30,
            'headers' => [
                'Accept' => 'text/xml,application/xml',
            ],
            'verify' => false,
        ]);
    }

    /**
     * Запустить парсинг OAI-PMH источника.
     *
     * Метод обходит все страницы результатов (следуя по resumptionToken),
     * сохраняет записи в таблицу contents и возвращает итоговую статистику.
     *
     * @param Source        $source     Источник данных с заполненным parse_settings.
     * @param callable|null $onProgress Коллбэк прогресса: function(int $total, string $title): void
     *
     * @return ParseResult Статистика: created, updated, skipped, errors.
     *
     * @throws \RuntimeException Если endpoint недоступен или конфигурация некорректна.
     */
    public function parse(Source $source, ?callable $onProgress = null): ParseResult
    {
        $settings = $this->resolveSettings($source);
        $result   = new ParseResult();

        $token      = null;  // OAI-PMH resumptionToken для пагинации
        $totalParsed = 0;

        Log::info("[OaiPmhParser] Старт парсинга источника «{$source->name}»", [
            'source_id' => $source->id,
            'endpoint' => $settings['oai_endpoint'],
            'limit' => $settings['batch_limit'],
        ]);

        do {
            // Формируем параметры запроса
            $params = $this->buildQueryParams($settings, $token);

            // Загружаем страницу OAI-PMH
            $xml = $this->fetchPage($settings['oai_endpoint'], $params);
            if ($xml === null) {
                $result->addError("Не удалось загрузить страницу OAI-PMH: {$settings['oai_endpoint']}");
                break;
            }

            $result->pagesFetched++;

            // Извлекаем общий размер списка (если сервер его сообщает)
            $completeListSize = $this->extractCompleteListSize($xml);
            if ($completeListSize !== null && $result->totalRecords === null) {
                $result->totalRecords = $completeListSize;
            }

            // Извлекаем список записей из XML
            $records = $this->extractRecords($xml);

            foreach ($records as $record) {
                // Проверяем лимит батча
                if ($settings['batch_limit'] > 0 && $totalParsed >= $settings['batch_limit']) {
                    Log::info("[OaiPmhParser] Достигнут лимит батча: {$settings['batch_limit']}");
                    break 2; // Выходим из обоих циклов
                }

                // Опциональное обогащение через скрапинг HTML-страницы
                $scraped = null;
                if ($settings['scrape_pages'] && isset($record['url'])) {
                    $scraped = $this->scraper->scrape($record['url']);
                }

                // Сохраняем запись в БД
                $this->saveRecord($source, $record, $scraped, $result);

                $totalParsed++;

                // Вызываем коллбэк прогресса
                if ($onProgress !== null) {
                    ($onProgress)($totalParsed, $record['title'] ?? '');
                }
            }

            // Получаем токен следующей страницы
            $token = $this->extractResumptionToken($xml);

            // Пауза между запросами к OAI-PMH (вежливость к серверу)
            if ($token !== null && $settings['request_delay_ms'] > 0) {
                usleep($settings['request_delay_ms'] * 1_000);
            }
        } while ($token !== null);

        Log::info("[OaiPmhParser] Парсинг завершён: {$result->summary()}", [
            'source_id' => $source->id,
        ]);

        return $result;
    }

    /**
     * Загрузить одну страницу OAI-PMH и вернуть разобранный SimpleXML-объект.
     *
     * @param string               $endpoint URL OAI-PMH сервиса.
     * @param array<string, string> $params   Query-параметры запроса.
     *
     * @return \SimpleXMLElement|null XML-ответ или null при ошибке.
     */
    private function fetchPage(string $endpoint, array $params): ?\SimpleXMLElement
    {
        $body = $this->fetchWithRetry($endpoint, ['query' => $params]);

        if ($body === null) {
            return null;
        }

        // Подавляем предупреждения XML-парсера и регистрируем неймспейсы
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            Log::error('[OaiPmhParser] Не удалось разобрать XML-ответ', [
                'endpoint' => $endpoint,
                'response_length' => strlen($body),
            ]);
            return null;
        }

        // Проверить наличие OAI-PMH ошибки в ответе (например, badResumptionToken)
        $xml->registerXPathNamespace('oai', self::NS_OAI);
        $errors = $xml->xpath('//oai:error');
        if ($errors && count($errors) > 0) {
            $errorCode    = (string) ($errors[0]['code'] ?? 'unknown');
            $errorMessage = (string) $errors[0];
            Log::warning("[OaiPmhParser] OAI-PMH ошибка: [{$errorCode}] {$errorMessage}");
            return null;
        }

        return $xml;
    }

    /**
     * Извлечь все записи контента из XML-ответа ListRecords.
     *
     * Каждая запись преобразуется в нормализованный массив полей.
     *
     * @param \SimpleXMLElement $xml XML-ответ OAI-PMH.
     *
     * @return list<array<string, mixed>> Список нормализованных записей.
     */
    private function extractRecords(\SimpleXMLElement $xml): array
    {
        $xml->registerXPathNamespace('oai', self::NS_OAI);
        $xml->registerXPathNamespace('dc', self::NS_DC);
        $xml->registerXPathNamespace('oai_dc', self::NS_OAI_DC);

        $recordNodes = $xml->xpath('//oai:record') ?: [];
        $records     = [];

        foreach ($recordNodes as $node) {
            // Пропускаем записи, помеченные как удалённые (OAI-PMH deleted status)
            $status = (string) ($node->header['status'] ?? '');
            if ($status === 'deleted') {
                continue;
            }

            $record = $this->normalizeRecord($node);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Преобразовать XML-узел записи OAI-PMH в нормализованный массив полей.
     *
     * Отдельные поля (например, dc:creator) могут встречаться несколько раз
     * в одной записи — все значения объединяются через ", ".
     *
     * @param \SimpleXMLElement $node XML-узел <record>.
     *
     * @return array<string, mixed>|null Нормализованная запись или null при отсутствии URL.
     */
    private function normalizeRecord(\SimpleXMLElement $node): ?array
    {
        $node->registerXPathNamespace('oai', self::NS_OAI);
        $node->registerXPathNamespace('dc', self::NS_DC);
        $node->registerXPathNamespace('oai_dc', self::NS_OAI_DC);

        // Идентификатор из заголовка (= URL статьи)
        $identifierNodes = $node->xpath('oai:header/oai:identifier');
        $url = $identifierNodes ? trim((string) $identifierNodes[0]) : null;

        // Метка времени последнего изменения записи в OAI-PMH
        $datestampNodes = $node->xpath('oai:header/oai:datestamp');
        $datestamp      = $datestampNodes ? trim((string) $datestampNodes[0]) : null;

        // Без URL запись бессмысленна — пропускаем
        if (!$url || !str_starts_with($url, 'http')) {
            return null;
        }

        // Dublin Core поля метаданных
        // Примечание: КиберЛенинка использует URL статьи одновременно как OAI-идентификатор
        // в <header> и как dc:identifier в <metadata>, поэтому URL берём из уже извлечённого $url.
        $titles = $node->xpath('oai:metadata/oai_dc:dc/dc:title');
        $creators = $node->xpath('oai:metadata/oai_dc:dc/dc:creator');
        $publishers = $node->xpath('oai:metadata/oai_dc:dc/dc:publisher');
        $dates = $node->xpath('oai:metadata/oai_dc:dc/dc:date');
        $descriptions = $node->xpath('oai:metadata/oai_dc:dc/dc:description');
        $subjects = $node->xpath('oai:metadata/oai_dc:dc/dc:subject');
        $languages = $node->xpath('oai:metadata/oai_dc:dc/dc:language');

        // Заголовок обязателен
        $title = $titles ? trim((string) $titles[0]) : null;
        if (!$title) {
            return null;
        }

        // Несколько авторов объединяем через ", "
        $authors = [];
        foreach ($creators ?? [] as $creator) {
            $name = trim((string) $creator);
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        // Ключевые слова из dc:subject
        $keywords = [];
        foreach ($subjects ?? [] as $subject) {
            $kw = trim((string) $subject);
            if ($kw !== '') {
                $keywords[] = mb_strtolower($kw);
            }
        }

        // Описание из dc:description (если присутствует в OAI-PMH)
        $descriptionParts = [];
        foreach ($descriptions ?? [] as $desc) {
            $d = trim((string) $desc);
            if ($d !== '') {
                $descriptionParts[] = $d;
            }
        }

        // Дата: сначала dc:date, затем datestamp из заголовка
        $rawDate = null;
        foreach ($dates ?? [] as $date) {
            $d = trim((string) $date);
            if ($d !== '') {
                $rawDate = $d;
                break;
            }
        }

        // Язык из dc:language
        $language = null;
        foreach ($languages ?? [] as $lang) {
            $l = trim((string) $lang);
            if ($l !== '') {
                $language = $l;
                break;
            }
        }

        // Внешний ID: slug из URL (часть пути после /article/n/)
        $externalId = $this->extractSlugFromUrl($url);

        return [
            'url' => $url,
            'external_id' => $externalId,
            'title' => $title,
            'author' => $authors ? implode(', ', $authors) : null,
            'publisher' => $publishers ? trim((string) $publishers[0]) : null,
            'description' => $descriptionParts ? implode("\n", $descriptionParts) : null,
            'keywords' => $keywords,
            'language' => $language,
            'published_at' => $rawDate ?? $datestamp,
        ];
    }

    /**
     * Извлечь resumptionToken из XML-ответа OAI-PMH для перехода к следующей странице.
     *
     * Пустой токен означает конец списка (сервер всё равно возвращает тег,
     * но с пустым значением).
     *
     * @param \SimpleXMLElement $xml XML-ответ.
     *
     * @return string|null Токен для следующего запроса или null, если страниц больше нет.
     */
    private function extractResumptionToken(\SimpleXMLElement $xml): ?string
    {
        $xml->registerXPathNamespace('oai', self::NS_OAI);
        $tokens = $xml->xpath('//oai:resumptionToken');

        if (!$tokens || count($tokens) === 0) {
            return null;
        }

        $token = trim((string) $tokens[0]);

        return $token !== '' ? $token : null;
    }

    /**
     * Извлечь полный размер списка записей (completeListSize атрибут resumptionToken).
     *
     * OAI-PMH серверы могут сообщать общее количество записей через этот атрибут.
     * Используется для расчёта прогресса в административной панели.
     *
     * @param \SimpleXMLElement $xml XML-ответ.
     *
     * @return int|null Общий размер списка или null, если сервер не сообщил.
     */
    private function extractCompleteListSize(\SimpleXMLElement $xml): ?int
    {
        $xml->registerXPathNamespace('oai', self::NS_OAI);
        $tokens = $xml->xpath('//oai:resumptionToken/@completeListSize');

        if ($tokens && count($tokens) > 0) {
            $value = (int) (string) $tokens[0];
            return $value > 0 ? $value : null;
        }

        return null;
    }

    /**
     * Сохранить или обновить запись контента в таблице contents.
     *
     * Дедупликация по полю url: если запись с таким URL уже существует,
     * она обновляется только при наличии изменений.
     *
     * Все записи сохраняются со статусом 'pending' (требуют модерации).
     * Администратор одобряет их вручную через панель управления.
     *
     * @param Source                 $source  Источник данных.
     * @param array<string, mixed>   $record  Нормализованная запись из OAI-PMH.
     * @param ScrapedArticleData|null $scraped Обогащённые данные со страницы статьи.
     * @param ParseResult            $result  Объект для накопления статистики.
     *
     * @return void
     */
    private function saveRecord(
        Source $source,
        array $record,
        ?ScrapedArticleData $scraped,
        ParseResult $result,
    ): void {
        // Мерджим данные из OAI-PMH и скрапинга (скрапинг имеет приоритет для description/language/date)
        $merged = $this->mergeData($record, $scraped);

        try {
            $existing = Contents::where('url', $merged['url'])->first();

            if ($existing === null) {
                // Новая запись
                Contents::create([
                    'source_id' => $source->id,
                    'title' => $merged['title'],
                    'description' => $merged['description'],
                    'author' => $merged['author'],
                    'type' => self::DEFAULT_CONTENT_TYPE,
                    'url' => $merged['url'],
                    'external_id' => $merged['external_id'],
                    'language' => $merged['language'],
                    'published_at' => $merged['published_at'],
                    'status' => 'pending',
                    'tags' => $merged['keywords'],
                ]);
                $result->created++;
            } else {
                // Обновляем только поля, которые изменились или были пустыми
                $updates = $this->buildUpdates($existing, $merged);

                if ($updates !== []) {
                    $existing->update($updates);
                    $result->updated++;
                } else {
                    $result->skipped++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[OaiPmhParser] Ошибка сохранения записи: {$merged['url']}", [
                'error' => $e->getMessage(),
            ]);
            $result->addError("Ошибка сохранения [{$merged['url']}]: {$e->getMessage()}");
        }
    }

    /**
     * Объединить данные OAI-PMH и скрапинга в один нормализованный массив.
     *
     * Приоритет: скрапинг > OAI-PMH > значение по умолчанию.
     * Исключение: URL и external_id всегда берутся из OAI-PMH (они каноничны).
     *
     * @param array<string, mixed>   $oai     Данные из OAI-PMH.
     * @param ScrapedArticleData|null $scraped Данные со страницы (или null).
     *
     * @return array<string, mixed> Итоговый нормализованный массив.
     */
    private function mergeData(array $oai, ?ScrapedArticleData $scraped): array
    {
        return [
            'url' => $oai['url'],
            'external_id' => $oai['external_id'],
            'title' => $oai['title'],
            'author' => $oai['author'],
            'description' => $scraped?->description ?? $oai['description'],
            'language' => $scraped?->language ?? $this->normalizeLanguage($oai['language'] ?? ''),
            'published_at' => $scraped?->publishedAt ?? $this->normalizeDate($oai['published_at'] ?? ''),
            // array_values сбрасывает ключи после array_unique, иначе JSON-кодирование
            // превращает список в объект ({"0":"foo","2":"bar"} вместо ["foo","bar"])
            'keywords' => array_values(array_unique(array_merge(
                $oai['keywords'] ?? [],
                $scraped?->keywords ?? [],
            ))),
        ];
    }

    /**
     * Сформировать массив полей для обновления существующей записи.
     *
     * Обновляем только поля, которые были пустыми или реально изменились.
     * Статус и source_id никогда не меняются при автоматическом обновлении.
     *
     * @param Contents             $existing Существующая запись из БД.
     * @param array<string, mixed> $merged   Новые данные после слияния.
     *
     * @return array<string, mixed> Массив изменённых полей (может быть пустым).
     */
    private function buildUpdates(Contents $existing, array $merged): array
    {
        $updates = [];

        // Обогащаем описание, если его не было
        if (empty($existing->description) && !empty($merged['description'])) {
            $updates['description'] = $merged['description'];
        }

        // Обогащаем автора, если не был задан
        if (empty($existing->author) && !empty($merged['author'])) {
            $updates['author'] = $merged['author'];
        }

        // Обновляем теги: добавляем новые к существующим (без дублей)
        if (!empty($merged['keywords'])) {
            $existingTags = $existing->tags ?? [];
            $mergedTags   = array_unique(array_merge($existingTags, $merged['keywords']));
            if (count($mergedTags) > count($existingTags)) {
                $updates['tags'] = array_values($mergedTags);
            }
        }

        // Устанавливаем дату публикации, если её не было
        if (empty($existing->published_at) && !empty($merged['published_at'])) {
            $updates['published_at'] = $merged['published_at'];
        }

        // Устанавливаем язык, если он не был определён ранее
        if (empty($existing->language) && !empty($merged['language'])) {
            $updates['language'] = $merged['language'];
        }

        return $updates;
    }

    /**
     * Получить и нормализовать настройки парсера из Source::parse_settings.
     *
     * Применяет значения по умолчанию для отсутствующих ключей.
     * Источник настроек: $source->parse_settings (JSON-поле из БД).
     *
     * @param Source $source Источник данных.
     *
     * @return array<string, mixed> Нормализованные настройки с дефолтами.
     *
     * @throws \RuntimeException Если не задан обязательный параметр oai_endpoint.
     */
    private function resolveSettings(Source $source): array
    {
        $settings = $source->parse_settings ?? [];

        // Определяем endpoint: из настроек или из поля api_endpoint
        $endpoint = $settings['oai_endpoint'] ?? $source->api_endpoint ?? null;

        if (!$endpoint) {
            throw new \RuntimeException(
                "Для источника «{$source->name}» не задан oai_endpoint в parse_settings."
            );
        }

        return [
            'oai_endpoint' => rtrim($endpoint, '/'),
            'metadata_prefix' => $settings['metadata_prefix'] ?? 'oai_dc',
            'oai_set' => $settings['oai_set'] ?? null,
            'batch_limit' => (int) ($settings['batch_limit'] ?? 0),
            'scrape_pages' => (bool) ($settings['scrape_pages'] ?? true),
            'scrape_delay_ms' => (int) ($settings['scrape_delay_ms'] ?? 800),
            'request_delay_ms' => (int) ($settings['request_delay_ms'] ?? 500),
        ];
    }

    /**
     * Сформировать query-параметры для запроса к OAI-PMH.
     *
     * При наличии resumptionToken используется только он (спецификация OAI-PMH 2.0).
     *
     * @param array<string, mixed> $settings    Нормализованные настройки парсера.
     * @param string|null          $token       Текущий resumptionToken (null для первой страницы).
     *
     * @return array<string, string> Query-параметры для Guzzle.
     */
    private function buildQueryParams(array $settings, ?string $token): array
    {
        if ($token !== null) {
            // Согласно спецификации OAI-PMH, при использовании resumptionToken
            // передаётся ТОЛЬКО этот токен, без метадата-префикса и set
            return [
                'verb' => 'ListRecords',
                'resumptionToken' => $token,
            ];
        }

        $params = [
            'verb' => 'ListRecords',
            'metadataPrefix' => $settings['metadata_prefix'],
        ];

        if ($settings['oai_set'] !== null) {
            $params['set'] = $settings['oai_set'];
        }

        return $params;
    }

    /**
     * Извлечь slug (уникальный идентификатор) статьи из URL КиберЛенинки.
     *
     * Пример: https://cyberleninka.ru/article/n/some-article-slug → some-article-slug
     * Используется как external_id для дедупликации и связи с RDF-ресурсом.
     *
     * @param string $url URL статьи.
     *
     * @return string|null Slug статьи или null, если URL имеет неожиданный формат.
     */
    private function extractSlugFromUrl(string $url): ?string
    {
        $path  = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return null;
        }

        $parts = explode('/', trim($path, '/'));
        return end($parts) ?: null;
    }

    /**
     * Нормализовать строку даты к формату Y-m-d.
     *
     * @param string $raw Сырая строка даты (ISO 8601, только год, год-месяц).
     *
     * @return string|null Дата Y-m-d или null при пустой/нераспознанной строке.
     */
    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}$/', $raw)) {
            return $raw . '-01-01';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw . '-01';
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Нормализовать код языка к допустимым значениям БД ('ru' или 'en').
     *
     * @param string $lang Код языка (может быть пустым).
     *
     * @return string 'ru' или 'en', никогда null.
     */
    private function normalizeLanguage(string $lang): string
    {
        $lang = mb_strtolower(trim($lang));

        if ($lang === '') {
            return self::DEFAULT_LANGUAGE;
        }

        return str_starts_with($lang, 'ru') ? 'ru' : 'en';
    }

    /**
     * Выполнить GET-запрос с повторными попытками при SSL/сетевых ошибках.
     *
     * Задержка между попытками увеличивается экспоненциально: 2s, 4s, 8s.
     * Логирует предупреждение при каждой неудачной попытке и ошибку при
     * исчерпании всех попыток.
     *
     * @param string               $url     URL запроса.
     * @param array<string, mixed> $options Опции Guzzle (query, headers и т.д.).
     *
     * @return string|null Тело ответа или null при окончательной ошибке.
     */
    private function fetchWithRetry(string $url, array $options): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->http->get($url, $options);
                return (string) $response->getBody();
            } catch (GuzzleException $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    Log::error("[OaiPmhParser] HTTP ошибка при загрузке OAI-PMH страницы", [
                        'endpoint' => $url,
                        'params' => $options,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                $delay = 2 ** $attempt; // 2s, 4s, 8s
                Log::warning("[OaiPmhParser] Попытка $attempt/{self::MAX_RETRIES} не удалась, повтор через {$delay}s", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
            }
        }

        return null;
    }
}
