<?php

declare(strict_types=1);

namespace App\DTO;

final class SearchFilters
{
    /**
     * @param string      $query    Поисковая строка.
     * @param string|null $type     Тип материала: article, video, course, documentation, book.
     * @param int|null    $yearFrom Год публикации «от» (включительно).
     * @param int|null    $yearTo   Год публикации «до» (включительно).
     * @param string|null $lang     Язык материала: ru или en.
     * @param int|null    $sourceId ID источника из таблицы sources.
     * @param string      $sort     Сортировка: relevance | date_desc | date_asc.
     * @param int         $perPage  Результатов на страницу (1–50).
     */
    public function __construct(
        public readonly string  $query,
        public readonly ?string $type     = null,
        public readonly ?int    $yearFrom = null,
        public readonly ?int    $yearTo   = null,
        public readonly ?string $lang     = null,
        public readonly ?int    $sourceId = null,
        public readonly string  $sort     = 'relevance',
        public readonly int     $perPage  = 15,
    ) {}
}
