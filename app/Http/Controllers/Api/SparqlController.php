<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contents;
use App\Services\RdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Контроллер для работы с семантическим слоем системы (SPARQL + RDF).
 *
 * Предоставляет два уровня доступа:
 *
 * Публичные эндпоинты (без аутентификации):
 *  - GET /api/sparql          — выполнить SPARQL SELECT к графу знаний
 *  - GET /api/sparql/status   — проверить доступность Fuseki
 *
 * Административные эндпоинты (auth:sanctum + admin):
 *  - POST /api/admin/sparql/update  — выполнить SPARQL UPDATE
 *  - POST /api/admin/rdf/upload     — загрузить RDF-дамп (Turtle / RDF-XML)
 *  - POST /api/admin/rdf/ontology   — загрузить онтологию в Fuseki
 *  - POST /api/admin/rdf/dataset    — инициализировать датасет TDB2
 *  - GET  /api/admin/rdf/export     — экспортировать контент (CSV / JSON-LD / RDF/XML)
 *
 * @package App\Http\Controllers\Api
 */
class SparqlController extends Controller
{
    /**
     * Создать экземпляр контроллера.
     *
     * @param RdfService $rdf Сервис взаимодействия с Fuseki (внедряется через IoC).
     */
    public function __construct(private readonly RdfService $rdf) {}

    /**
     * Выполнить SPARQL SELECT запрос к публичному графу знаний.
     *
     * Принимает SPARQL-запрос через параметр строки запроса «query».
     * Разрешены только операции чтения: SELECT, ASK, CONSTRUCT, DESCRIBE.
     * Операции записи (INSERT, DELETE, DROP и др.) блокируются регулярным выражением.
     *
     * Пример запроса:
     *   GET /api/sparql?query=PREFIX edu: <...> SELECT ?s ?t WHERE { ?s a edu:Article; dc:title ?t } LIMIT 10
     *
     * Формат ответа — стандартный SPARQL 1.1 JSON Results (bindings).
     *
     * @param Request $request HTTP-запрос с параметром «query».
     *
     * @return JsonResponse JSON с полями success, count, results (bindings).
     */
    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:5000',
        ]);

        $query = $request->input('query');

        if (preg_match('/\b(INSERT|DELETE|DROP|CLEAR|CREATE|LOAD|COPY|MOVE|ADD)\b/i', $query)) {
            return response()->json([
                'success' => false,
                'error'   => 'Публичный эндпоинт поддерживает только SELECT/ASK/CONSTRUCT/DESCRIBE.',
            ], 403);
        }

        $bindings = $this->rdf->sparqlSelect($query);

        return response()->json([
            'success' => true,
            'count'   => count($bindings),
            'results' => $bindings,
        ]);
    }

    /**
     * Получить статус доступности Apache Jena Fuseki.
     *
     * Выполняет ping к Fuseki и возвращает информацию о доступности,
     * имени датасета и URL хранилища.
     *
     * @return JsonResponse JSON с полями available, message, dataset, url.
     */
    public function status(): JsonResponse
    {
        $available = $this->rdf->ping();

        return response()->json([
            'available' => $available,
            'message'   => $available
                ? 'Fuseki доступен и готов к работе.'
                : 'Fuseki недоступен. Проверьте Docker-контейнер: docker compose up fuseki -d',
            'dataset'   => config('fuseki.dataset'),
            'url'       => config('fuseki.url'),
        ]);
    }

    /**
     * Построить граф семантических связей для конкретного материала.
     *
     * Выполняет SPARQL SELECT к Fuseki: ищет все ресурсы, у которых есть
     * хотя бы один общий тег (edu:hasTag) с запрошенным ресурсом.
     * Возвращает данные в формате {nodes, links} для react-force-graph-2d.
     *
     * Структура узлов:
     *  - type «center»   — текущий материал (центральный узел)
     *  - type «tag»      — тег-посредник (промежуточный узел)
     *  - type «resource» — связанный материал
     *
     * @param int $id ID материала в реляционной БД.
     *
     * @return JsonResponse JSON с полями success, nodes[], links[].
     */
    public function relatedGraph(int $id): JsonResponse
    {
        $content = Contents::approved()->find($id);

        if (!$content) {
            return response()->json(['success' => false, 'error' => 'Материал не найден'], 404);
        }

        if (!$this->rdf->ping()) {
            return response()->json([
                'success'   => false,
                'available' => false,
                'error'     => 'Семантическое хранилище недоступно.',
            ], 503);
        }

        $resourceUri  = $this->rdf->getResourceUri($content);
        $ontologyNs   = config('fuseki.ontology_ns', 'http://knowledge-aggregator.loc/ontology#');

        $sparql = <<<SPARQL
PREFIX edu:    <{$ontologyNs}>
PREFIX dc:     <http://purl.org/dc/elements/1.1/>

SELECT DISTINCT ?related ?relTitle ?relType ?tag WHERE {
  <{$resourceUri}> edu:hasTag ?tag .
  ?related edu:hasTag ?tag .
  ?related dc:title ?relTitle .
  OPTIONAL { ?related edu:contentType ?relType }
  FILTER(?related != <{$resourceUri}>)
}
ORDER BY ?tag
LIMIT 30
SPARQL;

        $bindings = $this->rdf->sparqlSelect($sparql);

        $centerNodeId = 'r_' . $id;

        $nodes = [[
            'id'        => $centerNodeId,
            'label'     => $content->title,
            'type'      => 'center',
            'contentId' => $id,
        ]];

        $links       = [];
        $addedNodes  = [$centerNodeId => true];
        $addedTags   = [];
        $addedLinks  = [];

        foreach ($bindings as $row) {
            $relUri  = $row['related']['value'] ?? null;
            $relTitle = $row['relTitle']['value'] ?? 'Без названия';
            $tag     = $row['tag']['value'] ?? null;
            $relType = $row['relType']['value'] ?? 'article';

            if (!$relUri || !$tag) {
                continue;
            }

            $relId     = (int) basename($relUri);
            $relNodeId = 'r_' . $relId;
            $tagNodeId = 'tag_' . md5($tag);

            if (!isset($addedNodes[$relNodeId])) {
                $nodes[]                 = [
                    'id'           => $relNodeId,
                    'label'        => $relTitle,
                    'type'         => 'resource',
                    'contentId'    => $relId,
                    'resourceType' => $relType,
                ];
                $addedNodes[$relNodeId] = true;
            }

            if (!isset($addedTags[$tagNodeId])) {
                $nodes[]             = ['id' => $tagNodeId, 'label' => $tag, 'type' => 'tag'];
                $addedTags[$tagNodeId] = true;
            }

            $l1 = $centerNodeId . '|' . $tagNodeId;
            $l2 = $relNodeId . '|' . $tagNodeId;

            if (!isset($addedLinks[$l1])) {
                $links[]          = ['source' => $centerNodeId, 'target' => $tagNodeId];
                $addedLinks[$l1] = true;
            }

            if (!isset($addedLinks[$l2])) {
                $links[]          = ['source' => $relNodeId, 'target' => $tagNodeId];
                $addedLinks[$l2] = true;
            }
        }

        return response()->json([
            'success'   => true,
            'available' => true,
            'nodes'     => $nodes,
            'links'     => $links,
        ]);
    }

    /**
     * Выполнить произвольный SPARQL UPDATE (только для администратора).
     *
     * Позволяет администратору напрямую модифицировать RDF-граф:
     * вставлять триплеты, удалять, загружать внешние графы.
     * Не имеет ограничений на тип операции — полный SPARQL 1.1 Update.
     *
     * @param Request $request HTTP-запрос с параметром «update».
     *
     * @return JsonResponse JSON с полями success, message.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'update' => 'required|string|max:10000',
        ]);

        $success = $this->rdf->sparqlUpdate($request->input('update'));

        return response()->json([
            'success' => $success,
            'message' => $success
                ? 'SPARQL UPDATE выполнен успешно.'
                : 'Ошибка выполнения SPARQL UPDATE. Подробности в storage/logs/laravel.log.',
        ]);
    }

    /**
     * Загрузить RDF-дамп в Fuseki через Graph Store Protocol.
     *
     * Принимает файл в одном из поддерживаемых форматов:
     *  - Turtle (.ttl) — text/turtle
     *  - RDF/XML (.rdf, .owl) — application/rdf+xml
     *  - Notation 3 (.n3) — text/n3
     *  - N-Triples (.nt) — application/n-triples
     *
     * Необязательный параметр «format» позволяет явно указать MIME-тип.
     * По умолчанию используется text/turtle.
     *
     * @param Request $request HTTP-запрос с файлом «file» и опциональным «format».
     *
     * @return JsonResponse JSON с полями success, message.
     */
    public function uploadDump(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240',
            'format' => 'nullable|string|in:text/turtle,application/rdf+xml,text/n3,application/n-triples',
        ]);

        $rdfContent = file_get_contents($request->file('file')->getPathname());
        $mimeType   = $request->input('format', 'text/turtle');

        $success = $this->rdf->uploadDump($rdfContent, $mimeType);

        return response()->json([
            'success' => $success,
            'message' => $success
                ? 'RDF-дамп успешно загружен в Fuseki.'
                : 'Ошибка загрузки RDF-дампа. Проверьте формат файла и доступность Fuseki.',
        ]);
    }

    /**
     * Загрузить OWL-онтологию из ontology/knowledge_aggregator.ttl в Fuseki.
     *
     * Перезаписывает существующую онтологию в графе новой версией из файла.
     * Вызывается при обновлении структуры классов или свойств онтологии.
     *
     * @return JsonResponse JSON с полями success, message.
     */
    public function uploadOntology(): JsonResponse
    {
        $success = $this->rdf->uploadOntology();

        return response()->json([
            'success' => $success,
            'message' => $success
                ? 'Онтология успешно загружена в Fuseki.'
                : 'Ошибка загрузки онтологии. Проверьте файл ontology/knowledge_aggregator.ttl.',
        ]);
    }

    /**
     * Инициализировать датасет TDB2 в Fuseki.
     *
     * Проверяет, существует ли датасет с именем из конфигурации fuseki.dataset.
     * Если нет — создаёт новый с типом хранилища TDB2 (постоянное дисковое хранилище).
     *
     * Безопасен для повторного вызова: если датасет уже существует, ничего не происходит.
     *
     * @return JsonResponse JSON с полями success, message.
     */
    public function ensureDataset(): JsonResponse
    {
        $success = $this->rdf->ensureDataset();

        return response()->json([
            'success' => $success,
            'message' => $success
                ? 'Датасет «' . config('fuseki.dataset') . '» готов к работе.'
                : 'Не удалось создать датасет. Проверьте доступность Fuseki и права доступа.',
        ]);
    }

    /**
     * Экспортировать одобренный контент в выбранном формате.
     *
     * Параметры запроса:
     *  - format (string, необязательный): «json-ld» (по умолчанию), «rdf-xml», «csv»
     *  - ids    (array,  необязательный): ограничить выгрузку конкретными ID записей
     *
     * Примеры:
     *   GET /api/admin/rdf/export?format=rdf-xml
     *   GET /api/admin/rdf/export?format=csv&ids[]=1&ids[]=2&ids[]=3
     *
     * Максимальный размер выгрузки ограничен 500 записями.
     *
     * @param Request $request HTTP-запрос с параметрами format и ids.
     *
     * @return Response|JsonResponse Файл для скачивания или JSON (для JSON-LD).
     */
    public function export(Request $request): Response|JsonResponse
    {
        $request->validate([
            'format' => 'nullable|string|in:json-ld,rdf-xml,csv',
            'ids'    => 'nullable|array',
            'ids.*'  => 'integer',
        ]);

        $format = $request->input('format', 'json-ld');

        $query = Contents::approved()->with('source');

        if ($request->filled('ids')) {
            $query->whereIn('id', $request->input('ids'));
        }

        $contents = $query->limit(500)->get();

        return match ($format) {
            'rdf-xml' => $this->exportRdfXml($contents),
            'csv'     => $this->exportCsv($contents),
            default   => response()->json($this->rdf->exportToJsonLd($contents)),
        };
    }

    /**
     * Сформировать HTTP-ответ с RDF/XML файлом для скачивания.
     *
     * @param \Illuminate\Support\Collection<int, Contents> $contents Коллекция контента.
     *
     * @return Response HTTP-ответ с заголовками Content-Type: application/rdf+xml.
     */
    private function exportRdfXml(mixed $contents): Response
    {
        return response($this->rdf->exportToRdfXml($contents), 200, [
            'Content-Type'        => 'application/rdf+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="knowledge_export.rdf"',
        ]);
    }

    /**
     * Сформировать HTTP-ответ с CSV-файлом для скачивания.
     *
     * Столбцы: id, title, author, type, language, url, external_id, published_at, tags.
     * Теги разделяются символом «|» внутри поля.
     *
     * @param \Illuminate\Support\Collection<int, Contents> $contents Коллекция контента.
     *
     * @return Response HTTP-ответ с заголовками Content-Type: text/csv.
     */
    private function exportCsv(mixed $contents): Response
    {
        $lines   = [];
        $lines[] = 'id,title,author,type,language,url,external_id,published_at,tags';

        foreach ($contents as $c) {
            $lines[] = implode(',', [
                $c->id,
                '"' . str_replace('"', '""', $c->title) . '"',
                '"' . str_replace('"', '""', $c->author ?? '') . '"',
                $c->type,
                $c->language ?? '',
                '"' . $c->url . '"',
                '"' . ($c->external_id ?? '') . '"',
                $c->published_at ? $c->published_at->format('Y-m-d') : '',
                '"' . implode('|', is_array($c->tags) ? $c->tags : []) . '"',
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="knowledge_export.csv"',
        ]);
    }
}
