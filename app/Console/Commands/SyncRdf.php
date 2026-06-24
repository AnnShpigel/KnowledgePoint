<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contents;
use App\Services\RdfService;
use Illuminate\Console\Command;

/**
 * Artisan-команда массовой синхронизации контента с RDF-хранилищем Fuseki.
 *
 * Предназначена для первичного наполнения графа знаний или для восстановления
 * данных после перезапуска контейнера Fuseki (при использовании in-memory датасета).
 *
 * Сценарии использования:
 *  - Первый запуск: php artisan rdf:sync --init
 *    (создать датасет + загрузить онтологию + синхронизировать контент)
 *  - Обновление онтологии: php artisan rdf:sync --ontology-only
 *  - Плановая синхронизация: php artisan rdf:sync
 *
 * @package App\Console\Commands
 */
class SyncRdf extends Command
{
    /**
     * Сигнатура команды с описанием доступных флагов.
     *
     * @var string
     */
    protected $signature = 'rdf:sync
                            {--init : Создать датасет TDB2 и загрузить онтологию перед синхронизацией}
                            {--ontology-only : Только (пере)загрузить онтологию в Fuseki без синхронизации контента}';

    /**
     * Краткое описание команды для вывода в списке artisan.
     *
     * @var string
     */
    protected $description = 'Синхронизировать одобренный контент с RDF-хранилищем Apache Jena Fuseki';

    /**
     * Точка входа Artisan-команды.
     *
     * Последовательность действий зависит от переданных флагов:
     *  1. Проверить доступность Fuseki.
     *  2. Если --init: создать датасет и загрузить онтологию.
     *  3. Если --ontology-only: загрузить только онтологию и завершить.
     *  4. Иначе: синхронизировать все одобренные записи контента.
     *
     * @param RdfService $rdf Сервис работы с Fuseki (внедряется через IoC-контейнер).
     *
     * @return int Код завершения: self::SUCCESS (0) или self::FAILURE (1).
     */
    public function handle(RdfService $rdf): int
    {
        $this->info('Проверка доступности Fuseki...');

        if (!$rdf->ping()) {
            $this->error('Fuseki недоступен. Убедитесь, что контейнер запущен:');
            $this->line('  docker compose up fuseki -d');
            return self::FAILURE;
        }

        $this->info('Fuseki доступен.');

        if ($this->option('init')) {
            $this->initFuseki($rdf);
        }

        if ($this->option('ontology-only')) {
            return $this->loadOntology($rdf);
        }

        return $this->syncContent($rdf);
    }

    /**
     * Инициализировать Fuseki: создать датасет TDB2 и загрузить онтологию.
     *
     * Вызывается при флаге --init. Обеспечивает, что датасет
     * «knowledge» существует и онтология загружена в граф
     * до начала синхронизации контента.
     *
     * @param RdfService $rdf Сервис работы с Fuseki.
     *
     * @return void
     */
    private function initFuseki(RdfService $rdf): void
    {
        $this->info('Инициализация датасета в Fuseki...');

        if ($rdf->ensureDataset()) {
            $this->info('Датасет «' . config('fuseki.dataset') . '» готов.');
        } else {
            $this->warn('Не удалось создать датасет. Возможно, он уже существует или Fuseki недоступен.');
        }

        $this->loadOntology($rdf);
    }

    /**
     * Загрузить OWL-онтологию из ontology/knowledge_aggregator.ttl в Fuseki.
     *
     * @param RdfService $rdf Сервис работы с Fuseki.
     *
     * @return int Код завершения: self::SUCCESS или self::FAILURE.
     */
    private function loadOntology(RdfService $rdf): int
    {
        $this->info('Загрузка онтологии ontology/knowledge_aggregator.ttl в Fuseki...');

        if ($rdf->uploadOntology()) {
            $this->info('Онтология успешно загружена.');
            return self::SUCCESS;
        }

        $this->error('Ошибка загрузки онтологии. Проверьте файл и логи Laravel.');
        return self::FAILURE;
    }

    /**
     * Синхронизировать все одобренные записи контента с Fuseki.
     *
     * Для каждой записи вызывает RdfService::syncContent(), которая
     * выполняет upsert триплетов в RDF-граф. Отображает прогресс-бар
     * и итоговую статистику успешных/неудачных операций.
     *
     * @param RdfService $rdf Сервис работы с Fuseki.
     *
     * @return int Код завершения: self::SUCCESS (все записи синхронизированы)
     *             или self::FAILURE (есть ошибки синхронизации).
     */
    private function syncContent(RdfService $rdf): int
    {
        $contents = Contents::approved()->with('source')->get();

        if ($contents->isEmpty()) {
            $this->warn('Нет одобренного контента для синхронизации.');
            return self::SUCCESS;
        }

        $this->info("Синхронизация {$contents->count()} записей в Fuseki...");

        $bar     = $this->output->createProgressBar($contents->count());
        $success = 0;
        $failed  = 0;

        $bar->start();

        foreach ($contents as $content) {
            if ($rdf->syncContent($content)) {
                $success++;
            } else {
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Успешно синхронизировано: {$success}");

        if ($failed > 0) {
            $this->warn("Ошибки: {$failed} (подробности в storage/logs/laravel.log)");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
