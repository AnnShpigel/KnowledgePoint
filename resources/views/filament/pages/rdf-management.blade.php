<x-filament-panels::page>

    {{-- Статус Fuseki --}}
    <x-filament::section>
        <x-slot name="heading">Статус хранилища</x-slot>

        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                @if($fusekiStatus === 'online')
                    <span class="inline-flex h-3 w-3 rounded-full bg-success-500 animate-pulse"></span>
                    <span class="text-success-600 dark:text-success-400 font-semibold">Fuseki Online</span>
                @elseif($fusekiStatus === 'offline')
                    <span class="inline-flex h-3 w-3 rounded-full bg-danger-500"></span>
                    <span class="text-danger-600 dark:text-danger-400 font-semibold">Fuseki Offline</span>
                @else
                    <span class="inline-flex h-3 w-3 rounded-full bg-gray-400"></span>
                    <span class="text-gray-500 font-semibold">Статус неизвестен</span>
                @endif
            </div>

            <span class="text-gray-400">|</span>

            <div class="text-sm text-gray-600 dark:text-gray-400">
                Датасет: <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-xs">
                    {{ config('fuseki.dataset', 'knowledge') }}
                </code>
            </div>

            <div class="text-sm text-gray-600 dark:text-gray-400">
                Endpoint: <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-xs">
                    {{ config('fuseki.url') }}
                </code>
            </div>
        </div>

        @if($fusekiStatus === 'offline')
            {{-- <x-filament::infolist.entry.hint> не существует в Filament v3 — используем plain HTML --}}
            <div class="mt-3 flex items-start gap-2 rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 px-4 py-3 text-sm text-danger-700 dark:text-danger-300">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 mt-0.5"/>
                <span>
                    Fuseki недоступен. Убедитесь, что Docker-контейнер запущен:
                    <code class="font-mono bg-danger-100 dark:bg-danger-900 px-1 rounded ml-1">./vendor/bin/sail up -d fuseki</code>
                </span>
            </div>
        @endif
    </x-filament::section>

    {{-- Статистика --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
        <x-filament::section>
            <div class="text-center">
                <div class="text-3xl font-bold text-success-600 dark:text-success-400">
                    {{ $approvedCount }}
                </div>
                <div class="text-sm text-gray-500 mt-1">Одобренных материалов</div>
                <div class="text-xs text-gray-400 mt-0.5">готовы к синхронизации</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-3xl font-bold text-primary-600 dark:text-primary-400">
                    {{ \App\Models\Contents::where('status', 'pending')->count() }}
                </div>
                <div class="text-sm text-gray-500 mt-1">Ожидают модерации</div>
                <div class="text-xs text-gray-400 mt-0.5">не синхронизированы</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-3xl font-bold text-gray-600 dark:text-gray-400">
                    {{ \App\Models\Contents::count() }}
                </div>
                <div class="text-sm text-gray-500 mt-1">Всего материалов</div>
                <div class="text-xs text-gray-400 mt-0.5">в базе данных</div>
            </div>
        </x-filament::section>
    </div>

    {{-- Операции с хранилищем --}}
    <x-filament::section class="mt-4">
        <x-slot name="heading">Управление хранилищем</x-slot>
        <x-slot name="description">
            Используйте кнопки в заголовке страницы для выполнения операций.
            Для массовой синхронизации (более 500 записей) рекомендуется CLI.
        </x-slot>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-plus-circle class="h-6 w-6 text-info-500 shrink-0 mt-0.5"/>
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Инициализировать датасет</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Создаёт датасет <code class="font-mono">knowledge</code> и загружает OWL-онтологию.
                            Безопасно при повторном запуске.
                        </p>
                        <code class="block mt-2 text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                            php artisan rdf:sync --init
                        </code>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-document-arrow-up class="h-6 w-6 text-warning-500 shrink-0 mt-0.5"/>
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Загрузить онтологию</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Загружает файл <code class="font-mono">ontology/knowledge_aggregator.ttl</code> в Fuseki.
                        </p>
                        <code class="block mt-2 text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                            php artisan rdf:sync --ontology-only
                        </code>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-cloud-arrow-up class="h-6 w-6 text-success-500 shrink-0 mt-0.5"/>
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Синхронизировать весь контент</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Пушит все одобренные материалы ({{ $approvedCount }} записей) в RDF-граф.
                        </p>
                        <code class="block mt-2 text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                            php artisan rdf:sync
                        </code>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-arrow-down-tray class="h-6 w-6 text-primary-500 shrink-0 mt-0.5"/>
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Экспорт данных</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Доступен через REST API:
                        </p>
                        <div class="mt-2 space-y-1">
                            <code class="block text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                                GET /api/admin/rdf/export?format=json-ld
                            </code>
                            <code class="block text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                                GET /api/admin/rdf/export?format=rdf-xml
                            </code>
                            <code class="block text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded font-mono">
                                GET /api/admin/rdf/export?format=csv
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- Быстрые SPARQL-запросы --}}
    <x-filament::section class="mt-4" collapsible collapsed>
        <x-slot name="heading">Диагностические запросы</x-slot>

        <div class="space-y-3 text-sm font-mono">
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-xs mb-1">Количество триплетов в графе:</p>
                <code class="block bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded text-xs">
                    SELECT (COUNT(*) as ?count) WHERE { ?s ?p ?o }
                </code>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-xs mb-1">Все классы ресурсов:</p>
                <code class="block bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded text-xs">
                    SELECT DISTINCT ?type WHERE { ?s rdf:type ?type } LIMIT 20
                </code>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400 text-xs mb-1">Последние добавленные статьи:</p>
                <code class="block bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded text-xs">
                    PREFIX dc: &lt;http://purl.org/dc/elements/1.1/&gt;
                    SELECT ?title ?date WHERE { ?s dc:title ?title . ?s dc:date ?date }
                    ORDER BY DESC(?date) LIMIT 10
                </code>
            </div>
        </div>

        <div class="mt-4">
            <a href="{{ route('filament.admin.pages.sparql-query-page') }}"
               class="text-primary-600 dark:text-primary-400 text-sm hover:underline flex items-center gap-1">
                <x-heroicon-o-arrow-right class="h-4 w-4"/>
                Перейти в SPARQL-редактор
            </a>
        </div>
    </x-filament::section>

</x-filament-panels::page>
