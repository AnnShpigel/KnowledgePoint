<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для полнотекстового поиска материалов
 *
 * Валидирует параметры запроса GET /api/search.
 * Маршрут публичный (не требует аутентификации), поэтому authorize() всегда true.
 * При наличии токена пользователь автоматически определяется через guard sanctum.
 */
class SearchRequest extends FormRequest
{
    /**
     * Разрешить выполнение запроса
     *
     * Поиск доступен всем пользователям (авторизованным и гостям).
     * История записывается только для авторизованных.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации параметров поиска
     *
     * q          — обязательная поисковая строка, минимум 2 символа
     * type       — необязательный фильтр по типу материала
     * year_from  — год публикации «от» (включительно), 1500–2030
     * year_to    — год публикации «до» (включительно), 1500–2030
     * lang       — язык материала: ru или en
     * source_id  — идентификатор источника (должен существовать в sources)
     * per_page   — количество результатов на страницу, от 1 до 50
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['nullable', 'string', 'in:article,video,course,documentation,book'],
            'year_from' => ['nullable', 'integer', 'min:1500', 'max:2030'],
            'year_to' => ['nullable', 'integer', 'min:1500', 'max:2030'],
            'lang' => ['nullable', 'string', 'in:ru,en'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
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
            'q.required' => 'Введите поисковый запрос',
            'q.min' => 'Запрос должен содержать не менее :min символов',
            'q.max' => 'Запрос не должен превышать :max символов',
            'type.in' => 'Допустимые типы: article, video, course, documentation, book',
            'year_from.integer' => 'Год «от» должен быть числом',
            'year_from.min' => 'Год не может быть меньше :min',
            'year_to.integer' => 'Год «до» должен быть числом',
            'lang.in' => 'Допустимые языки: ru, en',
            'source_id.exists' => 'Указанный источник не найден',
            'per_page.max' => 'Максимум :max результатов на страницу',
        ];
    }
}
