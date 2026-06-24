<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель заметки к сохранённому контенту (закладка с текстом)
 *
 * Хранит необязательную текстовую заметку, привязанную к записи UserContent.
 * Структура: user_contents → bookmark (1:1, опционально).
 * При удалении UserContent запись bookmark каскадно удаляется (onDelete cascade в миграции).
 *
 * @property int              $id
 * @property int              $user_content_id — FK на user_contents.id
 * @property string|null      $note            — заметка пользователя к материалу
 * @property \Carbon\Carbon   $created_at
 * @property \Carbon\Carbon   $updated_at
 */
class Bookmark extends Model
{
    use HasFactory;

    /**
     * Имя таблицы (singular, как в миграции)
     *
     * @var string
     */
    protected $table = 'bookmark';

    /**
     * Поля, доступные для массового заполнения
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_content_id',
        'note',
    ];

    /**
     * Связь с записью сохранённого контента пользователя
     *
     * @return BelongsTo<UserContent, Bookmark>
     */
    public function userContent(): BelongsTo
    {
        return $this->belongsTo(UserContent::class);
    }
}
