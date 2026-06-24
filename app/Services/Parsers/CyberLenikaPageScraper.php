<?php

declare(strict_types=1);

namespace App\Services\Parsers;

use App\Services\Parsers\DTO\ScrapedArticleData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Скрапер HTML-страниц статей КиберЛенинки.
 *
 * OAI-PMH-протокол КиберЛенинки не возвращает аннотацию и ключевые слова.
 * Данный класс извлекает недостающие поля путём парсинга HTML-страницы статьи.
 *
 * Стратегия извлечения данных (в порядке приоритета):
 *  1. JSON-LD блок (наиболее надёжный — структурированные данные Schema.org)
 *  2. Open Graph мета-теги (og:description)
 *  3. Стандартный мета-тег description
 *  4. HTML-элементы по CSS-подобным XPath-селекторам
 *
 * Важно: КиберЛенинка рендерит контент на сервере (SSR), поэтому данные
 * доступны в исходном HTML без выполнения JavaScript.
 *
 * @package App\Services\Parsers
 */
final class CyberLenikaPageScraper
{
    /**
     * User-Agent, имитирующий обычный браузер.
     * Некоторые серверы блокируют запросы с пустым или стандартным Guzzle UA.
     */
    private const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    /**
     * Таймаут HTTP-запроса к странице статьи (в секундах).
     */
    private const REQUEST_TIMEOUT = 15;

    /**
     * Максимальная длина описания (символов) для сохранения в БД.
     * Предотвращает сохранение слишком длинных текстов.
     */
    private const MAX_DESCRIPTION_LENGTH = 2000;

    /**
     * Максимальное число ключевых слов, сохраняемых как теги.
     */
    private const MAX_KEYWORDS = 15;

    private readonly Client $http;

    /**
     * @param int $delayMs Задержка между последовательными запросами (мс).
     *                     Снижает нагрузку на сервер КиберЛенинки.
     */
    public function __construct(private readonly int $delayMs = 800)
    {
        $this->http = new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            ],
            'verify' => false,
            'allow_redirects' => ['max' => 5],
        ]);
    }

    /**
     * Загрузить страницу статьи и извлечь недостающие метаданные.
     *
     * При любой ошибке (сетевой, парсинга) возвращает ScrapedArticleData::failed()
     * и логирует предупреждение — это не прерывает импорт остальных записей.
     *
     * @param string $url URL страницы статьи на КиберЛенинке.
     *
     * @return ScrapedArticleData Обогащённые данные или failed-объект при ошибке.
     */
    public function scrape(string $url): ScrapedArticleData
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1_000);
        }

        try {
            $response = $this->http->get($url);
            $html = (string) $response->getBody();
        } catch (GuzzleException $e) {
            Log::warning("[Scraper] Не удалось загрузить страницу: {$url}", [
                'error' => $e->getMessage(),
            ]);
            return ScrapedArticleData::failed();
        }

        return $this->extractData($html, $url);
    }

    /**
     * Извлечь данные из HTML-контента страницы.
     *
     * Последовательно применяет стратегии: JSON-LD → Open Graph → meta → XPath.
     *
     * @param string $html Исходный HTML страницы.
     * @param string $url  URL для логирования ошибок.
     *
     * @return ScrapedArticleData Распознанные метаданные.
     */
    private function extractData(string $html, string $url): ScrapedArticleData
    {
        // --- Стратегия 1: JSON-LD (наиболее надёжная) ---
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd !== null) {
            return $this->buildFromJsonLd($jsonLd);
        }

        // --- Стратегия 2: HTML-парсинг (DOM + XPath) ---
        return $this->buildFromDom($html, $url);
    }

    /**
     * Найти и декодировать первый JSON-LD блок с типом ScholarlyArticle или Article.
     *
     * КиберЛенинка добавляет Schema.org JSON-LD к каждой странице статьи.
     * Пример: {"@type":"ScholarlyArticle","name":"...","description":"..."}
     *
     * @param string $html Исходный HTML.
     *
     * @return array<string, mixed>|null Декодированный JSON-LD или null, если не найден.
     */
    private function extractJsonLd(string $html): ?array
    {
        // Ищем все script[type="application/ld+json"] блоки
        if (!preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches
        )) {
            return null;
        }

        foreach ($matches[1] as $jsonRaw) {
            $decoded = json_decode(trim($jsonRaw), true);
            if (!is_array($decoded)) {
                continue;
            }

            // JSON-LD может быть одним объектом или массивом объектов
            $candidates = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($candidates as $candidate) {
                $type = $candidate['@type'] ?? '';
                if (in_array($type, ['ScholarlyArticle', 'Article', 'NewsArticle'], true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Сформировать ScrapedArticleData из JSON-LD объекта.
     *
     * @param array<string, mixed> $data JSON-LD объект статьи.
     *
     * @return ScrapedArticleData Результат парсинга.
     */
    private function buildFromJsonLd(array $data): ScrapedArticleData
    {
        $description = $this->truncate(
            $this->cleanText((string) ($data['description'] ?? '')),
            self::MAX_DESCRIPTION_LENGTH
        );

        $keywords = $this->parseKeywords((string) ($data['keywords'] ?? ''));

        // Язык: inLanguage может быть строкой 'ru' или объектом {'@value': 'ru'}
        $lang = $data['inLanguage'] ?? null;
        $language = is_string($lang) ? $this->normalizeLanguage($lang) : null;

        // Дата публикации: datePublished может быть '2014', '2014-06-15', etc.
        $publishedAt = $this->normalizeDate((string) ($data['datePublished'] ?? ''));

        return new ScrapedArticleData(
            description: $description ?: null,
            language: $language,
            publishedAt: $publishedAt,
            keywords: $keywords,
            success: true,
        );
    }

    /**
     * Сформировать ScrapedArticleData с помощью DOM-парсинга HTML.
     *
     * Используется как запасная стратегия при отсутствии JSON-LD.
     * Парсит: Open Graph мета-теги, стандартный description, XPath-селекторы.
     *
     * @param string $html HTML страницы.
     * @param string $url  URL для логирования.
     *
     * @return ScrapedArticleData Результат парсинга.
     */
    private function buildFromDom(string $html, string $url): ScrapedArticleData
    {
        $dom = new \DOMDocument();

        // Подавляем предупреждения о нестандартном HTML
        // (libxml не поддерживает HTML5 полностью)
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        $description = $this->xpathFirst($xpath, [
            // Open Graph
            '//meta[@property="og:description"]/@content',
            // Стандартный мета-тег
            '//meta[@name="description"]/@content',
            // Блок аннотации КиберЛенинки (наблюдается в разных версиях вёрстки)
            '//div[contains(@class,"anot")]',
            '//p[contains(@class,"anot")]',
            '//div[contains(@class,"abstract")]//p',
            '//section[contains(@class,"abstract")]',
        ]);

        $keywordsRaw = $this->xpathFirst($xpath, [
            '//meta[@name="keywords"]/@content',
            '//div[contains(@class,"tags")]',
            '//ul[contains(@class,"keywords")]',
        ]);
        $keywords = $keywordsRaw ? $this->parseKeywords($keywordsRaw) : [];

        $langAttr = $this->xpathFirst($xpath, ['//html/@lang']);
        $language = $langAttr ? $this->normalizeLanguage($langAttr) : null;

        $dateRaw = $this->xpathFirst($xpath, [
            '//meta[@property="article:published_time"]/@content',
            '//time/@datetime',
            '//meta[@name="DC.date"]/@content',
        ]);
        $publishedAt = $dateRaw ? $this->normalizeDate($dateRaw) : null;

        return new ScrapedArticleData(
            description: $description ? $this->truncate($this->cleanText($description), self::MAX_DESCRIPTION_LENGTH) : null,
            language: $language,
            publishedAt: $publishedAt,
            keywords: $keywords,
            success: true,
        );
    }

    /**
     * Выполнить поиск по списку XPath-выражений и вернуть первый непустой результат.
     *
     * @param \DOMXPath     $xpath      Объект XPath для DOM-документа.
     * @param list<string>  $expressions Список XPath-выражений в порядке приоритета.
     *
     * @return string|null Текстовое содержимое первого совпавшего узла или null.
     */
    private function xpathFirst(\DOMXPath $xpath, array $expressions): ?string
    {
        foreach ($expressions as $expr) {
            $nodes = $xpath->query($expr);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $node  = $nodes->item(0);
            $value = trim($node?->nodeValue ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Разобрать строку ключевых слов в массив уникальных тегов.
     *
     * Поддерживает разделители: запятая, точка с запятой, вертикальная черта.
     *
     * @param string $raw Сырая строка ключевых слов.
     *
     * @return list<string> Список нормализованных ключевых слов.
     */
    private function parseKeywords(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;|]+/', $raw) ?: [];

        $keywords = [];
        foreach ($parts as $part) {
            $clean = mb_strtolower(trim($part));
            if ($clean !== '' && !in_array($clean, $keywords, true)) {
                $keywords[] = $clean;
            }
        }

        return array_slice($keywords, 0, self::MAX_KEYWORDS);
    }

    /**
     * Нормализовать строку даты к формату Y-m-d для хранения в БД.
     *
     * Принимает: '2014', '2014-06', '2014-06-15', '2014-06-15T10:00:00Z'.
     * Возвращает null при пустой строке или нераспознанном формате.
     *
     * @param string $raw Сырая строка даты.
     *
     * @return string|null Дата в формате Y-m-d или null.
     */
    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Только год: '2014' → '2014-01-01'
        if (preg_match('/^\d{4}$/', $raw)) {
            return $raw . '-01-01';
        }

        // Год-месяц: '2014-06' → '2014-06-01'
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw . '-01';
        }

        // ISO 8601 или Y-m-d
        try {
            $dt = new \DateTimeImmutable($raw);
            return $dt->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Нормализовать код языка к значениям, допустимым в таблице contents.
     *
     * Таблица принимает только 'ru' и 'en'. Прочие языки отображаются в 'en'
     * (по умолчанию для неизвестных), 'ru' — при явном совпадении.
     *
     * @param string $lang Код языка (ru, en, ru-RU, russian, ...).
     *
     * @return string 'ru' или 'en'.
     */
    private function normalizeLanguage(string $lang): string
    {
        $lang = mb_strtolower(trim($lang));

        return str_starts_with($lang, 'ru') ? 'ru' : 'en';
    }

    /**
     * Очистить текст от лишних пробелов, переносов строк и HTML-тегов.
     *
     * @param string $text Исходный текст.
     *
     * @return string Очищенный текст.
     */
    private function cleanText(string $text): string
    {
        // Убрать HTML-теги
        $text = strip_tags($text);
        // Заменить последовательности пробельных символов на один пробел
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Обрезать строку до заданного числа символов (UTF-8 безопасно).
     *
     * @param string $text  Исходная строка.
     * @param int    $limit Максимальная длина в символах.
     *
     * @return string Строка не длиннее $limit символов.
     */
    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 3) . '...';
    }
}
