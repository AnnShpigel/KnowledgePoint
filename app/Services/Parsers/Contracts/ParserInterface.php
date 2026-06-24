<?php

declare(strict_types=1);

namespace App\Services\Parsers\Contracts;

use App\Models\Source;
use App\Services\Parsers\DTO\ParseResult;

/**
 * Контракт парсера источника данных.
 *
 * Каждый тип источника (OAI-PMH, REST API, скрапер) реализует данный интерфейс.
 * Это позволяет использовать единый способ запуска парсинга через ParserFactory
 * независимо от конкретного протокола получения данных.
 *
 * Реализации: OaiPmhParser
 *
 * @package App\Services\Parsers\Contracts
 */
interface ParserInterface
{
    /**
     * Запустить парсинг указанного источника и вернуть результат.
     *
     * Метод является синхронным: он блокирует выполнение до завершения
     * парсинга. Для асинхронного запуска следует обернуть в Job/Queue.
     *
     * @param Source        $source     Источник данных для обработки.
     *                                  Конфигурация парсера берётся из $source->parse_settings.
     * @param callable|null $onProgress Необязательный коллбэк прогресса.
     *                                  Сигнатура: function(int $processed, string $title): void
     *
     * @return ParseResult Итоговая статистика: сколько записей создано, обновлено, пропущено.
     *
     * @throws \RuntimeException Если источник недоступен или конфигурация некорректна.
     */
    public function parse(Source $source, ?callable $onProgress = null): ParseResult;
}
