<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель лога парсинга источника данных.
 *
 * Каждая запись соответствует одному запуску парсера (через Artisan или API).
 * Используется для мониторинга состояния парсинга в административной панели.
 *
 * Жизненный цикл статуса:
 *   running → success (все записи обработаны без критических ошибок)
 *   running → failed  (критическая ошибка или нефатальные ошибки в части записей)
 *
 * @property int                     $id
 * @property int                     $source_id      ID источника
 * @property string                  $status         running | success | failed
 * @property int                     $items_parsed   Всего обработано записей
 * @property int                     $items_new      Новых записей создано
 * @property int                     $items_updated  Существующих записей обновлено
 * @property int                     $items_skipped  Записей пропущено (дубли)
 * @property int                     $pages_fetched  Страниц OAI-PMH загружено
 * @property int|null                $total_records  Общий размер списка на источнике
 * @property string|null             $error_message  Текст ошибок (нефатальные)
 * @property \Carbon\Carbon          $started_at     Время начала парсинга
 * @property \Carbon\Carbon|null     $finished_at    Время завершения
 * @property \Carbon\Carbon          $created_at
 * @property \Carbon\Carbon          $updated_at
 *
 * @package App\Models
 */
class ParseLog extends Model
{
    use HasFactory;

    /**
     * Поля, разрешённые для массового заполнения.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'status',
        'items_parsed',
        'items_new',
        'items_updated',
        'items_skipped',
        'pages_fetched',
        'total_records',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'items_parsed' => 'integer',
        'items_new' => 'integer',
        'items_updated' => 'integer',
        'items_skipped' => 'integer',
        'pages_fetched' => 'integer',
        'total_records' => 'integer',
    ];

    /**
     * Источник данных, к которому относится данный лог.
     *
     * @return BelongsTo<Source, ParseLog>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Вычислить продолжительность парсинга в секундах.
     *
     * @return int|null Количество секунд или null, если парсинг ещё не завершён.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return $this->finished_at->diffInSeconds($this->started_at);
    }

    /**
     * Вычислить среднюю скорость обработки записей.
     *
     * @return float|null Записей в секунду или null, если данные недоступны.
     */
    public function recordsPerSecond(): ?float
    {
        $duration = $this->durationSeconds();

        if ($duration === null || $duration === 0 || $this->items_parsed === 0) {
            return null;
        }

        return round($this->items_parsed / $duration, 2);
    }

    /**
     * Вычислить процент прогресса (для статуса 'running').
     *
     * @return int|null Процент от 0 до 100, или null если total_records неизвестен.
     */
    public function progressPercent(): ?int
    {
        if ($this->total_records === null || $this->total_records === 0) {
            return null;
        }

        return (int) min(100, round($this->items_parsed / $this->total_records * 100));
    }
}
