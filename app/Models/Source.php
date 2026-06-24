<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Модель источника данных для агрегации контента.
 *
 * Источник описывает внешнюю платформу (КиберЛенинка, РИНЦ, Europeana),
 * протокол доступа к её данным и параметры автоматического парсинга.
 *
 * Типы источников:
 *  - 'oai-pmh'  — протокол OAI-PMH 2.0 (КиберЛенинка, РИНЦ)
 *  - 'api'      — REST API (CrossRef, Europeana)
 *  - 'scraper'  — HTML-скрапинг
 *
 * @property int                                     $id
 * @property string                                  $name             Отображаемое название
 * @property string                                  $slug             Уникальный URL-идентификатор
 * @property string|null                             $description      Описание источника
 * @property string                                  $url              Базовый URL платформы
 * @property string|null                             $api_endpoint     Endpoint API или OAI-PMH
 * @property string|null                             $api_key          Ключ API (если требуется)
 * @property string                                  $type             oai-pmh | api | scraper
 * @property bool                                    $is_active        Активен ли для парсинга
 * @property int                                     $parse_interval   Интервал парсинга (сек)
 * @property \Carbon\Carbon|null                     $last_parsed_at   Дата последнего парсинга
 * @property array<string, mixed>|null               $parse_settings   JSON-настройки парсера
 * @property \Carbon\Carbon                          $created_at
 * @property \Carbon\Carbon                          $updated_at
 *
 * @package App\Models
 */
class Source extends Model
{
    use HasFactory;

    /**
     * Поля, разрешённые для массового заполнения.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'url',
        'api_endpoint',
        'api_key',
        'type',
        'is_active',
        'parse_interval',
        'last_parsed_at',
        'parse_settings',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_parsed_at' => 'datetime',
        'parse_interval' => 'integer',
        'parse_settings' => 'array',
    ];

    /**
     * Весь контент, агрегированный из этого источника.
     *
     * @return HasMany<Contents>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Contents::class);
    }

    /**
     * Все записи лога парсинга для этого источника.
     *
     * @return HasMany<ParseLog>
     */
    public function parseLogs(): HasMany
    {
        return $this->hasMany(ParseLog::class);
    }

    /**
     * Последняя запись лога парсинга (для вывода статуса в списке источников).
     *
     * Используется с eager loading: Source::with('latestParseLog')->get()
     *
     * @return HasOne<ParseLog>
     */
    public function latestParseLog(): HasOne
    {
        return $this->hasOne(ParseLog::class)->latestOfMany('started_at');
    }

    /**
     * Проверить, поддерживает ли источник автоматический парсинг.
     *
     * Используется в интерфейсе для отображения кнопки «Запустить парсинг».
     *
     * @return bool true, если тип источника имеет реализованный парсер.
     */
    public function isParserSupported(): bool
    {
        return in_array($this->type, ['oai-pmh'], true);
    }

    /**
     * Проверить, есть ли незавершённый запуск парсинга для этого источника.
     *
     * @return bool true, если есть активная запись ParseLog со статусом 'running'.
     */
    public function isParsingNow(): bool
    {
        return $this->parseLogs()
            ->where('status', 'running')
            ->exists();
    }
}
