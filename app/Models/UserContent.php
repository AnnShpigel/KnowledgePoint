<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Модель сохранённого контента пользователя
 *
 * Промежуточная таблица N:M между пользователями и контентом.
 * Является основой для закладок: каждая запись может иметь необязательную заметку (Bookmark).
 * Используется в личном кабинете как «сохранённые материалы».
 *
 * @property int              $id
 * @property int              $user_id
 * @property int              $content_id
 * @property \Carbon\Carbon   $created_at
 * @property \Carbon\Carbon   $updated_at
 *
 * @property-read User        $user
 * @property-read Contents    $content
 * @property-read Bookmark|null $bookmark
 */
class UserContent extends Model
{
    use HasFactory;

    /**
     * Поля, доступные для массового заполнения
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'content_id',
    ];

    /**
     * Владелец записи (пользователь, сохранивший материал)
     *
     * @return BelongsTo<User, UserContent>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Сохранённый материал
     *
     * @return BelongsTo<Contents, UserContent>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Contents::class);
    }

    /**
     * Заметка пользователя к сохранённому материалу (необязательна)
     *
     * @return HasOne<Bookmark>
     */
    public function bookmark(): HasOne
    {
        return $this->hasOne(Bookmark::class);
    }
}
