<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contents;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Сервис взаимодействия с Apache Jena Fuseki.
 *
 * Инкапсулирует всю логику работы с RDF-хранилищем:
 * синхронизацию контента в граф знаний, выполнение SPARQL-запросов,
 * загрузку онтологии и экспорт данных в семантических форматах.
 *
 * Fuseki является реализацией SPARQL 1.1 Protocol и хранит RDF-граф
 * в формате TDB2 (постоянное хранилище на диске).
 *
 * URI-пространство ресурсов задаётся через конфигурацию fuseki.resource_base
 * (по умолчанию: http://knowledge-aggregator.loc/).
 * Это локальный URI-идентификатор для RDF-графа — не веб-адрес.
 *
 * @package App\Services
 */
class RdfService
{
    /**
     * HTTP-клиент Guzzle для запросов к Fuseki.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Имя датасета в Fuseki (например, «knowledge»).
     *
     * @var string
     */
    private string $dataset;

    /**
     * Базовый URI для именования ресурсов в RDF-графе.
     * Всегда оканчивается на «/».
     *
     * @var string
     */
    private string $resourceBase;

    /**
     * Namespace онтологии (edu: prefix).
     * Используется при построении SPARQL-запросов и при экспорте.
     *
     * @var string
     */
    private string $ontologyNs;

    /**
     * Создать экземпляр сервиса.
     * Параметры подключения считываются из config/fuseki.php.
     */
    public function __construct()
    {
        $this->dataset = config('fuseki.dataset', 'knowledge');
        $this->resourceBase = rtrim(config('fuseki.resource_base', 'http://knowledge-aggregator.loc/'), '/') . '/';
        $this->ontologyNs = config('fuseki.ontology_ns', 'http://knowledge-aggregator.loc/ontology#');

        $this->client = new Client([
            'base_uri' => rtrim(config('fuseki.url', 'http://fuseki:3030'), '/'),
            'auth' => [config('fuseki.username', 'admin'), config('fuseki.password', 'admin')],
            'timeout' => (int) config('fuseki.timeout', 10),
            'http_errors' => false,
        ]);
    }

    /**
     * Сформировать URI ресурса контента в RDF-графе.
     *
     * Пример: http://knowledge-aggregator.loc/resource/42
     *
     * @param Contents $content Запись контента.
     *
     * @return string Уникальный URI ресурса.
     */
    public function getResourceUri(Contents $content): string
    {
        return $this->resourceBase . 'resource/' . $content->id;
    }

    /**
     * Сформировать URI источника данных в RDF-графе.
     *
     * Пример: http://knowledge-aggregator.loc/source/1
     *
     * @param int $sourceId Идентификатор источника.
     *
     * @return string Уникальный URI источника.
     */
    public function getSourceUri(int $sourceId): string
    {
        return $this->resourceBase . 'source/' . $sourceId;
    }

    /**
     * Синхронизировать запись контента с RDF-хранилищем Fuseki (операция upsert).
     *
     * Алгоритм:
     *  1. Если статус контента не «approved» — удалить ресурс из графа.
     *  2. Иначе — выполнить SPARQL UPDATE: сначала DELETE WHERE (удалить старые триплеты),
     *     затем INSERT DATA (записать актуальные триплеты).
     *
     * Тип ресурса в онтологии определяется полем Contents::$type:
     *  article → edu:Article, book → edu:Book, course → edu:Course,
     *  video → edu:Video, documentation → edu:Documentation.
     *
     * @param Contents $content Запись контента для синхронизации.
     *
     * @return bool true — синхронизация выполнена успешно, false — ошибка Fuseki.
     */
    public function syncContent(Contents $content): bool
    {
        if ($content->status !== 'approved') {
            return $this->removeContent($content);
        }

        $uri = $this->getResourceUri($content);
        $sourceUri = $this->getSourceUri($content->source_id);
        $class = $this->ontologyClass($content->type);

        $triples = "<{$uri}> a {$class}";
        $triples .= " ;\n    dc:title \"" . $this->esc($content->title) . "\"";

        if ($content->author) {
            $triples .= " ;\n    dc:creator \"" . $this->esc($content->author) . "\"";
        }

        if ($content->description) {
            $desc = mb_substr($content->description, 0, 1000);
            $triples .= " ;\n    dc:description \"" . $this->esc($desc) . "\"";
        }

        if ($content->published_at) {
            $triples .= " ;\n    dc:date \"" . $content->published_at->format('Y-m-d') . "\"^^xsd:date";
        }

        $triples .= " ;\n    schema:url <" . $content->url . ">";
        $triples .= " ;\n    edu:contentType \"" . $content->type . "\"";
        $triples .= " ;\n    edu:verificationStatus \"approved\"";

        if ($content->language) {
            $triples .= " ;\n    edu:inLanguage \"" . $content->language . "\"";
        }

        if ($content->external_id) {
            $triples .= " ;\n    dc:identifier \"" . $this->esc($content->external_id) . "\"";
            $triples .= " ;\n    edu:externalId \"" . $this->esc($content->external_id) . "\"";
        }

        $triples .= " ;\n    edu:fromSource <{$sourceUri}>";

        if (is_array($content->tags)) {
            foreach ($content->tags as $tag) {
                $triples .= " ;\n    edu:hasTag \"" . $this->esc((string) $tag) . "\"";
            }
        }

        $triples .= ' .';

        $update = $this->prefixes()
            . "\nDELETE WHERE { <{$uri}> ?p ?o } ;\n"
            . "INSERT DATA { {$triples} }";

        return $this->sparqlUpdate($update);
    }

    /**
     * Удалить все RDF-триплеты ресурса из графа Fuseki.
     *
     * Выполняет SPARQL UPDATE: DELETE WHERE { <uri> ?p ?o }.
     * Используется при смене статуса контента на rejected/pending
     * или при физическом удалении записи из базы данных.
     *
     * @param Contents $content Запись контента для удаления из графа.
     *
     * @return bool true — удаление выполнено, false — ошибка Fuseki.
     */
    public function removeContent(Contents $content): bool
    {
        $uri = $this->getResourceUri($content);
        $update = $this->prefixes() . "\nDELETE WHERE { <{$uri}> ?p ?o }";

        return $this->sparqlUpdate($update);
    }

    /**
     * Выполнить SPARQL SELECT/ASK/CONSTRUCT/DESCRIBE запрос к Fuseki.
     *
     * Возвращает массив bindings в стандартном формате SPARQL 1.1 JSON Results:
     * каждый элемент — ассоциативный массив вида:
     *   ['varName' => ['type' => 'literal'|'uri', 'value' => '...']]
     *
     * При недоступности Fuseki или ошибке запроса возвращает пустой массив
     * и записывает детали в журнал Laravel.
     *
     * @param string $query Текст SPARQL-запроса (SELECT/ASK/CONSTRUCT/DESCRIBE).
     *
     * @return array<int, array<string, array<string, string>>> Bindings из результата запроса.
     */
    public function sparqlSelect(string $query): array
    {
        try {
            $response = $this->client->get("/{$this->dataset}/sparql", [
                'query' => ['query' => $query, 'format' => 'json'],
                'headers' => ['Accept' => 'application/sparql-results+json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('Fuseki SELECT вернул статус: ' . $response->getStatusCode());
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['results']['bindings'] ?? [];
        } catch (GuzzleException $e) {
            Log::error('Fuseki SPARQL SELECT: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Выполнить SPARQL UPDATE (INSERT DATA / DELETE WHERE / LOAD и др.).
     *
     * Отправляет запрос на эндпоинт /{dataset}/update методом POST
     * с телом вида update=<sparql>.
     *
     * @param string $update Текст SPARQL UPDATE запроса.
     *
     * @return bool true — запрос выполнен успешно (HTTP 200/204), false — ошибка.
     */
    public function sparqlUpdate(string $update): bool
    {
        try {
            $response = $this->client->post("/{$this->dataset}/update", [
                'form_params' => ['update' => $update],
            ]);

            return in_array($response->getStatusCode(), [200, 204]);
        } catch (GuzzleException $e) {
            Log::error('Fuseki SPARQL UPDATE: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Загрузить RDF-дамп в граф Fuseki через Graph Store Protocol (GSP).
     *
     * Поддерживаемые MIME-типы:
     *  - text/turtle            (формат Turtle, рекомендуется)
     *  - application/rdf+xml    (формат RDF/XML)
     *  - text/n3                (формат Notation 3)
     *  - application/n-triples  (формат N-Triples)
     *
     * @param string $rdfContent Содержимое RDF-файла в виде строки.
     * @param string $mimeType   MIME-тип содержимого. По умолчанию «text/turtle».
     *
     * @return bool true — дамп загружен успешно (HTTP 200/201/204), false — ошибка.
     */
    public function uploadDump(string $rdfContent, string $mimeType = 'text/turtle'): bool
    {
        try {
            $response = $this->client->post("/{$this->dataset}/data", [
                'body' => $rdfContent,
                'headers' => ['Content-Type' => $mimeType],
            ]);

            return in_array($response->getStatusCode(), [200, 201, 204]);
        } catch (GuzzleException $e) {
            Log::error('Fuseki uploadDump: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Загрузить онтологию из файла ontology/knowledge_aggregator.ttl в Fuseki.
     *
     * Файл находится в корне проекта и содержит OWL 2 описание классов
     * и свойств предметной области в формате Turtle.
     *
     * @return bool true — онтология загружена, false — файл не найден или ошибка Fuseki.
     */
    public function uploadOntology(): bool
    {
        $path = base_path('ontology/knowledge_aggregator.ttl');

        if (!file_exists($path)) {
            Log::error('Файл онтологии не найден: ' . $path);
            return false;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            Log::error('Не удалось прочитать файл онтологии: ' . $path);
            return false;
        }

        return $this->uploadDump($content, 'text/turtle');
    }

    /**
     * Экспортировать коллекцию контента в строку формата RDF/XML.
     *
     * Генерирует валидный RDF/XML документ с декларацией XML-пространств имён
     * для Dublin Core (dc:), Schema.org (schema:) и кастомного словаря (edu:).
     * Каждая запись контента становится отдельным элементом rdf:Description.
     *
     * @param Collection<int, Contents> $contents Коллекция одобренного контента.
     *
     * @return string Строка в формате RDF/XML с UTF-8 кодировкой.
     */
    public function exportToRdfXml(Collection $contents): string
    {
        $ns = $this->ontologyNs;
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rdf:RDF',
            '  xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"',
            '  xmlns:dc="http://purl.org/dc/elements/1.1/"',
            '  xmlns:schema="https://schema.org/"',
            '  xmlns:xsd="http://www.w3.org/2001/XMLSchema#"',
            '  xmlns:edu="' . $ns . '">',
            '',
        ];

        foreach ($contents as $content) {
            $uri = htmlspecialchars($this->getResourceUri($content), ENT_XML1);
            $typeUri = htmlspecialchars($ns . ucfirst($content->type), ENT_XML1);
            $srcUri = htmlspecialchars($this->getSourceUri($content->source_id), ENT_XML1);

            $lines[] = '  <rdf:Description rdf:about="' . $uri . '">';
            $lines[] = '    <rdf:type rdf:resource="' . $typeUri . '"/>';
            $lines[] = '    <dc:title>' . htmlspecialchars($content->title, ENT_XML1) . '</dc:title>';

            if ($content->author) {
                $lines[] = '    <dc:creator>' . htmlspecialchars($content->author, ENT_XML1) . '</dc:creator>';
            }

            if ($content->description) {
                $desc = mb_substr($content->description, 0, 500);
                $lines[] = '    <dc:description>' . htmlspecialchars($desc, ENT_XML1) . '</dc:description>';
            }

            if ($content->published_at) {
                $lines[] = '    <dc:date rdf:datatype="http://www.w3.org/2001/XMLSchema#date">'
                    . $content->published_at->format('Y-m-d') . '</dc:date>';
            }

            $lines[] = '    <schema:url>' . htmlspecialchars($content->url, ENT_XML1) . '</schema:url>';
            $lines[] = '    <edu:contentType>' . htmlspecialchars($content->type, ENT_XML1) . '</edu:contentType>';
            $lines[] = '    <edu:verificationStatus>' . htmlspecialchars($content->status, ENT_XML1) . '</edu:verificationStatus>';

            if ($content->language) {
                $lines[] = '    <edu:inLanguage>' . htmlspecialchars($content->language, ENT_XML1) . '</edu:inLanguage>';
            }

            if ($content->external_id) {
                $lines[] = '    <dc:identifier>' . htmlspecialchars($content->external_id, ENT_XML1) . '</dc:identifier>';
            }

            if (is_array($content->tags)) {
                foreach ($content->tags as $tag) {
                    $lines[] = '    <edu:hasTag>' . htmlspecialchars((string) $tag, ENT_XML1) . '</edu:hasTag>';
                }
            }

            $lines[] = '    <edu:fromSource rdf:resource="' . $srcUri . '"/>';
            $lines[] = '  </rdf:Description>';
            $lines[] = '';
        }

        $lines[] = '</rdf:RDF>';

        return implode("\n", $lines);
    }

    /**
     * Экспортировать коллекцию контента в структуру JSON-LD.
     *
     * Возвращает ассоциативный массив с ключами @context и @graph,
     * готовый к сериализации через json_encode().
     *
     * JSON-LD (JSON for Linked Data) — стандарт W3C для публикации
     * связанных данных в формате JSON с явным указанием семантики.
     *
     * @param Collection<int, Contents> $contents Коллекция одобренного контента.
     *
     * @return array{@context: array<string, string>, @graph: array<int, array<string, mixed>>}
     *         Структура JSON-LD для последующей сериализации.
     */
    public function exportToJsonLd(Collection $contents): array
    {
        $ns = $this->ontologyNs;
        $graph = [];

        foreach ($contents as $content) {
            $item = [
                '@id' => $this->getResourceUri($content),
                '@type' => $ns . ucfirst($content->type),
                'dc:title' => $content->title,
                'schema:url' => $content->url,
                'edu:contentType' => $content->type,
                'edu:verificationStatus' => $content->status,
                'edu:fromSource' => ['@id' => $this->getSourceUri($content->source_id)],
            ];

            if ($content->author) {
                $item['dc:creator'] = $content->author;
            }

            if ($content->description) {
                $item['dc:description'] = mb_substr($content->description, 0, 500);
            }

            if ($content->published_at) {
                $item['dc:date'] = [
                    '@value' => $content->published_at->format('Y-m-d'),
                    '@type' => 'xsd:date',
                ];
            }

            if ($content->language) {
                $item['edu:inLanguage'] = $content->language;
            }

            if ($content->external_id) {
                $item['dc:identifier'] = $content->external_id;
            }

            if (is_array($content->tags)) {
                $item['edu:hasTag'] = $content->tags;
            }

            $graph[] = $item;
        }

        return [
            '@context' => [
                'dc' => 'http://purl.org/dc/elements/1.1/',
                'schema' => 'https://schema.org/',
                'edu' => $ns,
                'xsd' => 'http://www.w3.org/2001/XMLSchema#',
            ],
            '@graph' => $graph,
        ];
    }

    /**
     * Проверить доступность сервера Fuseki.
     *
     * Отправляет GET-запрос к эндпоинту /$/ping с таймаутом 3 секунды.
     * Метод безопасен для вызова даже при недоступном Fuseki —
     * исключения перехватываются и возвращается false.
     *
     * @return bool true — Fuseki отвечает, false — сервер недоступен.
     */
    public function ping(): bool
    {
        try {
            $response = $this->client->get('/$/ping', ['timeout' => 3]);

            return $response->getStatusCode() === 200;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Убедиться, что датасет существует в Fuseki.
     * Если датасет отсутствует — создать новый с типом хранилища TDB2.
     *
     * TDB2 — постоянное дисковое хранилище Apache Jena.
     * Данные сохраняются в /fuseki/databases/{dataset} внутри контейнера
     * и переживают перезапуск через Docker volume.
     *
     * @return bool true — датасет существует или успешно создан, false — ошибка.
     */
    public function ensureDataset(): bool
    {
        try {
            $check = $this->client->get('/$/datasets/' . $this->dataset);

            if ($check->getStatusCode() === 200) {
                return true;
            }

            $create = $this->client->post('/$/datasets', [
                'form_params' => [
                    'dbName' => $this->dataset,
                    'dbType' => 'tdb2',
                ],
            ]);

            return in_array($create->getStatusCode(), [200, 201]);
        } catch (GuzzleException $e) {
            Log::error('Fuseki ensureDataset: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Определить класс онтологии по типу контента.
     *
     * Возвращает SPARQL-совместимое имя класса с префиксом edu:.
     * Для неизвестных типов возвращает базовый класс edu:KnowledgeResource.
     *
     * @param string $type Тип контента из перечисления: article|book|course|video|documentation.
     *
     * @return string Имя класса онтологии (например, «edu:Article»).
     */
    private function ontologyClass(string $type): string
    {
        return match ($type) {
            'article' => 'edu:Article',
            'book' => 'edu:Book',
            'course' => 'edu:Course',
            'video' => 'edu:Video',
            'documentation' => 'edu:Documentation',
            default => 'edu:KnowledgeResource',
        };
    }

    /**
     * Экранировать строку для безопасного использования в SPARQL-литерале.
     *
     * Заменяет символы, недопустимые внутри двойных кавычек в SPARQL:
     * обратный слеш, двойные кавычки, переносы строк, табуляцию.
     *
     * @param string $value Исходная строка.
     *
     * @return string Экранированная строка, безопасная для вставки в "литерал".
     */
    private function esc(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace("\r", '\\r', $value);
        $value = str_replace("\t", '\\t', $value);

        return $value;
    }

    /**
     * Сформировать стандартный блок PREFIX для SPARQL-запросов.
     *
     * Включает пространства имён: rdf, rdfs, owl, xsd, dc, schema, edu.
     * Должен предварять любой SPARQL-запрос, использующий эти префиксы.
     *
     * @return string Многострочный блок PREFIX-объявлений.
     */
    private function prefixes(): string
    {
        return implode("\n", [
            'PREFIX rdf:    <http://www.w3.org/1999/02/22-rdf-syntax-ns#>',
            'PREFIX rdfs:   <http://www.w3.org/2000/01/rdf-schema#>',
            'PREFIX owl:    <http://www.w3.org/2002/07/owl#>',
            'PREFIX xsd:    <http://www.w3.org/2001/XMLSchema#>',
            'PREFIX dc:     <http://purl.org/dc/elements/1.1/>',
            'PREFIX schema: <https://schema.org/>',
            "PREFIX edu:    <{$this->ontologyNs}>",
        ]);
    }
}
