<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель материала базы знаний
 *
 * Представляет агрегированный контент из внешних источников.
 * Материал проходит модерацию: pending → approved / rejected.
 * Только approved-материалы доступны пользователям и синхронизируются с Fuseki.
 *
 * @property int         $id
 * @property int         $source_id
 * @property string      $title
 * @property string|null $description
 * @property string|null $author
 * @property string      $type       — article | video | course | documentation | book
 * @property string      $url        — прямая ссылка на первоисточник (уникальная)
 * @property string|null $external_id — DOI, ISBN, OAI-идентификатор
 * @property string      $language   — ru | en
 * @property string|null $published_at
 * @property string      $status     — pending | approved | rejected
 * @property array|null  $tags
 * @property int         $views
 */
class Contents extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'source_id',
        'title',
        'description',
        'author',
        'type',
        'url',
        'external_id',
        'language',
        'published_at',
        'status',
        'tags',
        'views',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'published_at' => 'date',
        'tags' => 'array',
    ];

    // ── Связи ────────────────────────────────────────────────────────────────

    /**
     * Источник, из которого получен материал
     *
     * @return BelongsTo<Source, Contents>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Записи пользователей, сохранивших этот материал в закладки
     *
     * @return HasMany<UserContent>
     */
    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }

    /**
     * Теги материала через сводную таблицу content_tag
     *
     * @return BelongsToMany<Tag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'content_tag');
    }

    // ── Скопы (Query Scopes) ─────────────────────────────────────────────────

    /**
     * Фильтровать только одобренные материалы (status = approved)
     *
     * Используется в поиске и публичных запросах.
     *
     * @param Builder<Contents> $query
     * @return Builder<Contents>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Фильтровать материалы на модерации (status = pending)
     *
     * @param Builder<Contents> $query
     * @return Builder<Contents>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
