<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\SearchFilters;
use App\Models\Contents;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Wamania\Snowball\StemmerFactory;

/**
 * Гибридный поисковый сервис: MySQL FULLTEXT + SPARQL-обогащение.
 *
 * Алгоритм поиска:
 *  1. MySQL FULLTEXT IN BOOLEAN MODE по полям title, description, author
 *     с автоматическим добавлением «*» к каждому слову ≥ 3 символов
 *     (префиксный поиск: «систем» → находит «система», «системы», «системный»).
 *  2. Дополнительный LIKE-поиск по JSON-полю tags.
 *  3. Сортировка по релевантности MATCH-score при наличии поискового запроса.
 *  4. SPARQL-обогащение через Apache Jena Fuseki: поиск по тегам в RDF-графе.
 *     Возвращает ресурсы, не найденные MySQL, но семантически связанные с запросом.
 *     Работает только на первой странице, не блокирует ответ при недоступности Fuseki.
 */
class SearchService
{
    public function __construct(private readonly RdfService $rdf) {}

    /**
     * Выполнить гибридный поиск.
     *
     * @param SearchFilters $filters Параметры поиска и фильтрации.
     * @param User|null     $user    Авторизованный пользователь для записи истории.
     *
     * @return array{paginator: LengthAwarePaginator, semantic_items: array}
     */
    public function search(SearchFilters $filters, ?User $user = null): array
    {
        $hasQuery    = mb_strlen(trim($filters->query)) >= 2;
        $boolQuery   = $hasQuery ? $this->buildBooleanQuery($filters->query) : '';

        $query = Contents::query()
            ->approved()
            ->with('source:id,name,slug,url');

        if ($hasQuery) {
            $query->where(function (Builder $q) use ($filters, $boolQuery): void {
                $this->applyTextSearch($q, $filters->query, $boolQuery);
            });
        }

        $this->applyFilters($query, $filters);
        $this->applyOrdering($query, $boolQuery, $filters->sort);

        $paginator = $query->paginate($filters->perPage);

        // SPARQL-обогащение: только первая страница, только при наличии запроса
        $semanticItems = [];
        if ($hasQuery && $paginator->currentPage() === 1) {
            $existingIds   = collect($paginator->items())->pluck('id')->toArray();
            $semanticItems = $this->sparqlEnrichment($filters->query, $existingIds);
        }

        if ($user !== null && ($paginator->total() > 0 || !empty($semanticItems))) {
            $this->recordHistory($user, $filters);
        }

        return [
            'paginator'      => $paginator,
            'semantic_items' => $semanticItems,
        ];
    }

    /**
     * Применить поиск по тексту к построителю запроса.
     *
     * Логика:
     * - Если построен boolean-запрос (≥1 слово ≥ 3 символов):
     *     MATCH(title, description, author) IN BOOLEAN MODE — основной поиск,
     *     LOWER(tags) LIKE — дополнение для тегов (вне FULLTEXT-индекса).
     * - Иначе (короткий запрос < 3 символов):
     *     LIKE по всем текстовым полям.
     */
    private function applyTextSearch(Builder $query, string $term, string $boolQuery): void
    {
        $like = '%' . addcslashes(mb_strtolower($term), '%_\\') . '%';

        if ($boolQuery !== '') {
            $query->whereRaw(
                'MATCH(title, description, author) AGAINST (? IN BOOLEAN MODE)',
                [$boolQuery],
            );
            // Теги хранятся как JSON-текст, они не в FULLTEXT-индексе
            $query->orWhereRaw('LOWER(tags) LIKE ?', [$like]);
        } else {
            $query->whereRaw('LOWER(title) LIKE ?',       [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                ->orWhereRaw('LOWER(author) LIKE ?',      [$like])
                ->orWhereRaw('LOWER(tags) LIKE ?',        [$like]);
        }
    }

    /**
     * Построить строку BOOLEAN MODE запроса с морфологической нормализацией.
     *
     * Алгоритм для каждого слова запроса:
     *  1. Очистить управляющие символы BOOLEAN MODE.
     *  2. Привести к нижнему регистру и применить Snowball-стеммер (Russian).
     *  3. Добавить суффикс «*» (prefix-wildcard) к основе ≥ 3 символов.
     *
     * Эффект: «Система» и «Системы» → основа «систем» → запрос «систем*»
     * → MySQL находит «система», «системы», «системный», «систематика» и т.д.
     *
     * Для слов < 3 символов или если стеммер вернул слишком короткую основу —
     * слово пропускается (для них работает fallback LIKE в applyTextSearch).
     */
    private function buildBooleanQuery(string $query): string
    {
        $stemmer = StemmerFactory::create('Russian');
        $words   = preg_split('/[\s,;]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $parts   = [];
        $seen    = [];

        foreach ($words as $word) {
            $clean = preg_replace('/[+\-<>()"~*@]/u', '', $word);
            $clean = trim(mb_strtolower($clean ?? ''));

            if (mb_strlen($clean) < 2) {
                continue;
            }

            // Получаем морфологическую основу через Snowball
            $stem = $stemmer->stem($clean);

            // Используем основу если она осмысленной длины, иначе — исходное слово
            $token = (mb_strlen($stem) >= 3) ? $stem : $clean;

            if (mb_strlen($token) >= 3 && !isset($seen[$token])) {
                $parts[]      = $token . '*';
                $seen[$token] = true;
            }
        }

        return implode(' ', $parts);
    }

    private function applyFilters(Builder $query, SearchFilters $filters): void
    {
        $query
            ->when($filters->type,     fn(Builder $q, string $t) => $q->where('type', $t))
            ->when($filters->yearFrom, fn(Builder $q, int $y)    => $q->where('published_at', '>=', "{$y}-01-01"))
            ->when($filters->yearTo,   fn(Builder $q, int $y)    => $q->where('published_at', '<=', "{$y}-12-31"))
            ->when($filters->lang,     fn(Builder $q, string $l) => $q->where('language', $l))
            ->when($filters->sourceId, fn(Builder $q, int $s)    => $q->where('source_id', $s));
    }

    /**
     * Установить порядок сортировки.
     *
     * - При наличии boolQuery и sort=relevance: по убыванию MATCH-score, затем по дате.
     * - sort=date_asc:  по возрастанию даты публикации.
     * - По умолчанию:   по убыванию даты публикации.
     */
    private function applyOrdering(Builder $query, string $boolQuery, string $sort): void
    {
        if ($boolQuery !== '' && $sort === 'relevance') {
            $query->orderByRaw(
                'MATCH(title, description, author) AGAINST (? IN BOOLEAN MODE) DESC, published_at DESC',
                [$boolQuery],
            );
            return;
        }

        match ($sort) {
            'date_asc' => $query->orderBy('published_at'),
            default    => $query->orderByDesc('published_at'),
        };
    }

    /**
     * Найти ресурсы в RDF-графе Fuseki, теги которых семантически связаны с запросом.
     *
     * Выполняет SPARQL SELECT с FILTER(CONTAINS(LCASE(?tag), "слово")) для каждого
     * слова запроса. Возвращает одобренные материалы из MySQL, чьи URI присутствуют
     * в RDF-графе, но не вошли в MySQL-результаты текущей страницы.
     *
     * Метод безопасен: любые исключения (Fuseki недоступен, таймаут, ошибка SPARQL)
     * перехватываются и возвращается пустой массив — поиск не прерывается.
     *
     * @param string $query      Поисковый запрос пользователя.
     * @param int[]  $excludeIds ID материалов, уже присутствующих в MySQL-результатах.
     *
     * @return array<int, array<string, mixed>> Массив сериализованных объектов Contents.
     */
    private function sparqlEnrichment(string $query, array $excludeIds): array
    {
        try {
            $ontologyNs = config('fuseki.ontology_ns', 'http://knowledge-aggregator.loc/ontology#');
            $stemmer    = StemmerFactory::create('Russian');

            $rawWords = array_values(array_filter(
                preg_split('/[\s,;]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY),
                static fn(string $w): bool => mb_strlen($w) >= 2,
            ));

            if (empty($rawWords)) {
                return [];
            }

            // Нормализуем через стеммер — CONTAINS будет искать корень слова в тегах
            $words = array_unique(array_map(
                static fn(string $w): string => $stemmer->stem($w),
                $rawWords,
            ));
            $words = array_values(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 2));

            if (empty($words)) {
                return [];
            }

            $filters   = array_map(
                static fn(string $w): string => 'CONTAINS(LCASE(STR(?tag)), "' . addslashes($w) . '")',
                $words,
            );
            $filterStr = implode(' || ', $filters);

            $sparql = <<<SPARQL
PREFIX edu:  <{$ontologyNs}>
PREFIX dc:   <http://purl.org/dc/elements/1.1/>

SELECT DISTINCT ?resource WHERE {
  ?resource edu:hasTag ?tag .
  FILTER({$filterStr})
}
LIMIT 15
SPARQL;

            $bindings = $this->rdf->sparqlSelect($sparql);

            $ids = [];
            foreach ($bindings as $row) {
                $uri = $row['resource']['value'] ?? null;
                if (!$uri) {
                    continue;
                }
                $id = (int) basename($uri);
                if ($id > 0 && !in_array($id, $excludeIds, true)) {
                    $ids[] = $id;
                }
            }

            if (empty($ids)) {
                return [];
            }

            return Contents::approved()
                ->whereIn('id', $ids)
                ->with('source:id,name,slug,url')
                ->get()
                ->toArray();

        } catch (\Throwable) {
            return [];
        }
    }

    private function recordHistory(User $user, SearchFilters $filters): void
    {
        $activeFilters = array_filter([
            'type'      => $filters->type,
            'year_from' => $filters->yearFrom,
            'year_to'   => $filters->yearTo,
            'lang'      => $filters->lang,
            'source_id' => $filters->sourceId,
            'sort'      => $filters->sort !== 'relevance' ? $filters->sort : null,
        ]);

        SearchHistory::create([
            'user_id' => $user->id,
            'query'   => $filters->query,
            'filters' => empty($activeFilters) ? null : $activeFilters,
        ]);
    }
}
