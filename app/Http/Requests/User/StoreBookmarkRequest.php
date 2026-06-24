<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для добавления материала в закладки пользователя
 *
 * Валидирует входящие данные при сохранении материала (POST /api/user/bookmarks).
 * Авторизация выполняется через middleware auth:sanctum до попадания в контроллер.
 */
class StoreBookmarkRequest extends FormRequest
{
    /**
     * Разрешить выполнение запроса
     *
     * Дополнительная авторизация не нужна — пользователь уже проверен middleware.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для сохранения закладки
     *
     * content_id — обязателен, должен существовать в таблице contents.
     * note       — необязателен, максимум 500 символов.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Кастомные сообщения об ошибках валидации на русском языке
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content_id.required' => 'Укажите идентификатор материала',
            'content_id.integer' => 'Идентификатор материала должен быть числом',
            'content_id.exists' => 'Материал не найден',
            'note.max' => 'Заметка не должна превышать 500 символов',
        ];
    }
}
