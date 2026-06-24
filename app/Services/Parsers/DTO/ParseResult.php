<?php

declare(strict_types=1);

namespace App\Services\Parsers\DTO;

/**
 * Результат выполнения парсинга источника данных.
 *
 * Объект-значение (Value Object): накапливает статистику в процессе обхода
 * и передаётся из парсера в команду/контроллер для записи в parse_logs.
 *
 * Использование:
 * ```php
 * $result = new ParseResult();
 * $result->created++;
 * $result->addError("Не удалось загрузить страницу: $url");
 * echo $result->total();    // 1
 * echo $result->hasErrors(); // true
 * ```
 *
 * @package App\Services\Parsers\DTO
 */
final class ParseResult
{
    /**
     * Количество новых записей, добавленных в базу данных.
     *
     * @var int
     */
    public int $created = 0;

    /**
     * Количество существующих записей, данные которых были обновлены.
     *
     * @var int
     */
    public int $updated = 0;

    /**
     * Количество пропущенных записей (дубликаты, без изменений).
     *
     * @var int
     */
    public int $skipped = 0;

    /**
     * Количество страниц OAI-PMH (или батчей API), успешно загруженных.
     *
     * @var int
     */
    public int $pagesFetched = 0;

    /**
     * Общее число записей на источнике (если источник сообщает completeListSize).
     *
     * Значение null означает, что источник не предоставил эту информацию.
     *
     * @var int|null
     */
    public ?int $totalRecords = null;

    /**
     * Список нефатальных ошибок, возникших при обработке отдельных записей.
     *
     * Фатальные ошибки (недоступность источника) бросают исключение,
     * нефатальные (не удалось скрапить одну страницу) накапливаются здесь.
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * Получить суммарное количество обработанных записей.
     *
     * @return int Сумма created + updated + skipped.
     */
    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped;
    }

    /**
     * Проверить, были ли нефатальные ошибки в процессе парсинга.
     *
     * @return bool true, если массив ошибок не пуст.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Добавить сообщение о нефатальной ошибке в лог.
     *
     * @param string $message Описание ошибки (URL, причина).
     *
     * @return void
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Сформировать краткую строку-сводку для вывода в CLI.
     *
     * Пример: "Создано: 85, обновлено: 12, пропущено: 3, ошибок: 0"
     *
     * @return string Человекочитаемая сводка результата.
     */
    public function summary(): string
    {
        return sprintf(
            'Создано: %d, обновлено: %d, пропущено: %d, ошибок: %d',
            $this->created,
            $this->updated,
            $this->skipped,
            count($this->errors),
        );
    }
}
