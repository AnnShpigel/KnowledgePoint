<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Apache Jena Fuseki (RDF Triplestore) Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки подключения к SPARQL-хранилищу Apache Jena Fuseki.
    | Fuseki используется для хранения онтологии и выполнения SPARQL-запросов
    | к графу знаний по верифицированным научно-образовательным ресурсам.
    |
    */

    // URL Fuseki сервера (внутри Docker: http://fuseki:3030)
    'url' => env('FUSEKI_URL', 'http://fuseki:3030'),

    // Имя датасета в Fuseki
    'dataset' => env('FUSEKI_DATASET', 'knowledge'),

    // Учётные данные Fuseki admin API
    'username' => env('FUSEKI_USERNAME', 'admin'),
    'password' => env('FUSEKI_PASSWORD', 'admin'),

    // Базовый URI для ресурсов системы
    'resource_base' => env('FUSEKI_RESOURCE_BASE', 'http://knowledge-aggregator.loc/'),

    // Namespace онтологии (edu: prefix)
    'ontology_ns' => env('FUSEKI_ONTOLOGY_NS', 'http://knowledge-aggregator.loc/ontology#'),

    // Таймаут HTTP запросов к Fuseki (секунды)
    'timeout' => (int) env('FUSEKI_TIMEOUT', 10),
];
