<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use App\Services\Parsers\Contracts\ParserInterface;
use App\Services\Parsers\CyberLenikaPageScraper;
use App\Services\Parsers\MkrfApiParser;
use App\Services\Parsers\OaiPmhParser;

/**
 * Фабрика парсеров источников данных.
 *
 * Создаёт конкретную реализацию ParserInterface в зависимости от типа источника.
 * Единственная точка создания парсеров в приложении — позволяет легко
 * добавлять новые типы (например, 'api' для CrossRef) без изменения команд/контроллеров.
 *
 * Таблица соответствий:
 *  - 'oai-pmh' → OaiPmhParser
 *  - 'api'     → (не реализован, зарезервировано для будущих источников)
 *  - 'scraper' → (не реализован, зарезервировано)
 *
 * @package App\Services
 */
final class ParserFactory
{
    /**
     * @param CyberLenikaPageScraper $pageScraper Скрапер HTML-страниц (вводится через DI).
     */
    public function __construct(
        private readonly CyberLenikaPageScraper $pageScraper,
    ) {}

    /**
     * Создать парсер для указанного источника данных.
     *
     * Тип парсера определяется полем source.type из базы данных.
     *
     * @param Source $source Источник данных, для которого требуется парсер.
     *
     * @return ParserInterface Готовый к использованию парсер.
     *
     * @throws \InvalidArgumentException Если тип источника не поддерживается.
     */
    public function make(Source $source): ParserInterface
    {
        return match ($source->type) {
            'oai-pmh' => new OaiPmhParser($this->pageScraper),
            'api'     => new MkrfApiParser(),
            default   => throw new \InvalidArgumentException(
                "Тип источника «{$source->type}» не поддерживается. "
                . 'Реализованные типы: oai-pmh, api.'
            ),
        };
    }

    /**
     * Проверить, поддерживается ли тип указанного источника.
     *
     * Используется в команде и контроллере для информирования пользователя
     * до попытки запуска парсинга.
     *
     * @param Source $source Источник для проверки.
     *
     * @return bool true, если для данного типа есть реализация парсера.
     */
    public function supports(Source $source): bool
    {
        return in_array($source->type, ['oai-pmh', 'api'], true);
    }
}
