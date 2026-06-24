<?php

declare(strict_types=1);

namespace App\Services\Parsers\DTO;

/**
 * Данные статьи, полученные путём скрапинга HTML-страницы источника.
 *
 * Объект-значение: создаётся в CyberLenikaPageScraper и передаётся
 * в OaiPmhParser для обогащения записи, полученной через OAI-PMH.
 *
 * OAI-PMH-ответ КиберЛенинки не содержит аннотацию и ключевые слова —
 * их можно получить только со страницы статьи.
 *
 * Неустановленные поля остаются null — парсер использует это для решения,
 * брать значение из OAI-PMH или пропустить обогащение.
 *
 * @package App\Services\Parsers\DTO
 */
final class ScrapedArticleData
{
    /**
     * @param string|null   $description   Аннотация / реферат статьи.
     * @param string|null   $language      Язык публикации (ru, en, ...).
     * @param string|null   $publishedAt   Дата публикации в формате Y-m-d или Y.
     * @param list<string>  $keywords      Список ключевых слов / тегов.
     * @param bool          $success       Успешно ли выполнено скрапирование.
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $language = null,
        public readonly ?string $publishedAt = null,
        public readonly array   $keywords = [],
        public readonly bool    $success = true,
    ) {}

    /**
     * Создать «пустой» объект для случая, когда скрапинг завершился с ошибкой.
     *
     * @return self Экземпляр с success=false и всеми полями null/[].
     */
    public static function failed(): self
    {
        return new self(success: false);
    }

    /**
     * Проверить, есть ли хоть какие-то обогащённые данные.
     *
     * @return bool true, если хотя бы одно поле заполнено.
     */
    public function hasData(): bool
    {
        return $this->description !== null
            || $this->language !== null
            || $this->publishedAt !== null
            || $this->keywords !== [];
    }
}
